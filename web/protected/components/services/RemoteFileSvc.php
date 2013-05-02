<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
/**
 * RemoteFileSvc.php
 * Remote File Storage Service giving REST access to file storage.
 */
class RemoteFileSvc extends BaseFileSvc
{
	//*************************************************************************
	//	Members
	//*************************************************************************

	/**
	 * @var AwsS3Blob|WindowsAzureBlob|null
	 */
	protected $blobSvc;
	/**
	 * @var string
	 */
	protected $storageContainer;

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * Create a new RemoteFileSvc
	 *
	 * @param  array $config
	 *
	 * @throws InvalidArgumentException
	 * @throws Exception
	 */
	public function __construct( $config )
	{
		parent::__construct( $config );

		// Validate blob setup
		$store_name = Utilities::getArrayValue( 'storage_name', $config, '' );
		if ( empty( $store_name ) )
		{
			throw new InvalidArgumentException( 'Blob container name can not be empty.' );
		}
		$this->storageContainer = $store_name;
		try
		{
			$type = isset( $config['storage_type'] ) ? $config['storage_type'] : '';
			$credentials = isset( $config['credentials'] ) ? $config['credentials'] : '';
			switch ( strtolower( $type ) )
			{
				case 'azure blob':
					$local_dev = isset( $credentials['local_dev'] ) ? Utilities::boolval( $credentials['local_dev'] ) : false;
					$accountName = isset( $credentials['account_name'] ) ? $credentials['account_name'] : '';
					$accountKey = isset( $credentials['account_key'] ) ? $credentials['account_key'] : '';
					try
					{
						$this->blobSvc = new WindowsAzureBlob( $local_dev, $accountName, $accountKey );
					}
					catch ( Exception $ex )
					{
						throw new Exception( "Unexpected Windows Azure Blob Service Exception:\n{$ex->getMessage()}" );
					}
					break;
				case 'aws s3':
					$accessKey = isset( $credentials['access_key'] ) ? $credentials['access_key'] : '';
					$secretKey = isset( $credentials['secret_key'] ) ? $credentials['secret_key'] : '';
					try
					{
						$this->blobSvc = new AwsS3Blob( $accessKey, $secretKey );
					}
					catch ( Exception $ex )
					{
						throw new Exception( "Unexpected Amazon S3 Service Exception:\n{$ex->getMessage()}" );
					}
					break;
				default:
					throw new Exception( "Invalid Blob Storage Type in configuration environment." );
					break;
			}
		}
		catch ( Exception $ex )
		{
			throw new Exception( "Error creating blob file manager.\n{$ex->getMessage()}" );
		}
	}

	/**
	 * Object destructor
	 */
	public function __destruct()
	{
		if ( isset( $this->blobSvc ) )
		{
			// any special destruction for blob services?
			unset( $this->blobSvc );
		}
		unset( $this->storageContainer );
	}

	/**
	 * Creates the container for this file management if it does not already exist
	 *
	 * @throws Exception
	 */
	public function checkContainerForWrite()
	{
		try
		{
			if ( !$this->blobSvc->containerExists( $this->storageContainer ) )
			{
				$this->blobSvc->createContainer( $this->storageContainer );
			}
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param $path
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function folderExists( $path )
	{
		$path = FileUtilities::fixFolderPath( $path );
		try
		{
			if ( $this->blobSvc->containerExists( $this->storageContainer ) )
			{
				if ( $this->blobSvc->blobExists( $this->storageContainer, $path ) )
				{
					return true;
				}
			}
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}

		return false;
	}

	/**
	 * @param       $path
	 * @param  bool $include_files
	 * @param  bool $include_folders
	 * @param  bool $full_tree
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getFolderContent( $path, $include_files = true, $include_folders = true, $full_tree = false )
	{
		$path = FileUtilities::fixFolderPath( $path );
		$delimiter = ( $full_tree ) ? '' : '/';
		try
		{
			$files = array();
			$folders = array();
			if ( $this->blobSvc->containerExists( $this->storageContainer ) )
			{
				if ( !empty( $path ) )
				{
					if ( !$this->blobSvc->blobExists( $this->storageContainer, $path ) )
					{
						throw new Exception( "Folder '$path' does not exist in storage.", ErrorCodes::NOT_FOUND );
					}
				}
				$results = $this->blobSvc->listBlobs( $this->storageContainer, $path, $delimiter );
				foreach ( $results as $blob )
				{
					$fullPathName = $blob['name'];
					$shortName = substr_replace( $fullPathName, '', 0, strlen( $path ) );
					if ( '/' == substr( $fullPathName, strlen( $fullPathName ) - 1 ) )
					{
						// folders
						if ( $include_folders )
						{
							$shortName = FileUtilities::getNameFromPath( $shortName );
							$folders[] = array(
								'name'         => $shortName,
								'path'         => $fullPathName,
								'lastModified' => isset( $blob['lastModified'] ) ? $blob['lastModified'] : null
							);
						}
					}
					else
					{
						// files
						if ( $include_files )
						{
							$blob['path'] = $fullPathName;
							$blob['name'] = $shortName;
							$files[] = $blob;
						}
					}
				}
			}

			return array( "folder" => $folders, "file" => $files );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $path
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getFolderProperties( $path )
	{
		$path = FileUtilities::fixFolderPath( $path );
		try
		{
			if ( !$this->blobSvc->blobExists( $this->storageContainer, $path ) )
			{
				throw new Exception( "Folder '$path' does not exist in storage.", ErrorCodes::NOT_FOUND );
			}
			$folder = $this->blobSvc->getBlobData( $this->storageContainer, $path );
			$properties = json_decode( $folder, true ); // array of properties
			return array( 'folder' => array( array( 'path' => $path, 'properties' => $properties ) ) );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param       $path
	 * @param bool  $is_public
	 * @param array $properties
	 * @param bool  $check_exist
	 *
	 * @return void
	 * @throws Exception
	 */
	public function createFolder( $path, $is_public = true, $properties = array(), $check_exist = true )
	{
		if ( empty( $path ) )
		{
			throw new Exception( "Invalid empty path.", ErrorCodes::BAD_REQUEST );
		}
		$parent = FileUtilities::getParentFolder( $path );
		$path = FileUtilities::fixFolderPath( $path );

		// does this folder already exist?
		if ( $this->folderExists( $path ) )
		{
			if ( $check_exist )
			{
				throw new Exception( "Folder '$path' already exists.", ErrorCodes::BAD_REQUEST );
			}

			return;
		}
		// does this folder's parent exist?
		if ( !empty( $parent ) && ( !$this->folderExists( $parent ) ) )
		{
			if ( $check_exist )
			{
				throw new Exception( "Folder '$parent' does not exist.", ErrorCodes::NOT_FOUND );
			}
			$this->createFolder( $parent, $is_public, $properties, false );
		}

		try
		{
			// create the folder
			$this->checkContainerForWrite(); // need to be able to write to storage
			$properties = ( empty( $properties ) ) ? '' : json_encode( $properties );
			$this->blobSvc->putBlobData( $this->storageContainer, $path, $properties );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $dest_path
	 * @param string $src_path
	 * @param bool   $check_exist
	 *
	 * @return void
	 * @throws Exception
	 */
	public function copyFolder( $dest_path, $src_path, $check_exist = false )
	{
		// does this file already exist?
		if ( !$this->folderExists( $src_path ) )
		{
			throw new Exception( "Folder '$src_path' does not exist.", ErrorCodes::NOT_FOUND );
		}
		if ( $this->folderExists( $dest_path ) )
		{
			if ( ( $check_exist ) )
			{
				throw new Exception( "Folder '$dest_path' already exists.", ErrorCodes::BAD_REQUEST );
			}
		}
		// does this file's parent folder exist?
		$parent = FileUtilities::getParentFolder( $dest_path );
		if ( !empty( $parent ) && ( !$this->folderExists( $parent ) ) )
		{
			throw new Exception( "Folder '$parent' does not exist.", ErrorCodes::NOT_FOUND );
		}
		try
		{
			// create the folder
			$this->checkContainerForWrite(); // need to be able to write to storage
			$this->blobSvc->copyBlob( $this->storageContainer, $dest_path, $this->storageContainer, $src_path );
			// now copy content of folder...
			$blobs = $this->blobSvc->listBlobs( $this->storageContainer, $src_path );
			if ( !empty( $blobs ) )
			{
				foreach ( $blobs as $blob )
				{
					$srcName = $blob['name'];
					if ( ( 0 !== strcasecmp( $src_path, $srcName ) ) )
					{
						// not self properties blob
						$name = FileUtilities::getNameFromPath( $srcName );
						$fullPathName = $dest_path . $name;
						$this->blobSvc->copyBlob( $this->storageContainer, $fullPathName, $this->storageContainer, $srcName );
					}
				}
			}
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $path
	 * @param array  $properties
	 *
	 * @return void
	 * @throws Exception
	 */
	public function updateFolderProperties( $path, $properties = array() )
	{
		$path = FileUtilities::fixFolderPath( $path );
		// does this folder exist?
		if ( !$this->folderExists( $path ) )
		{
			throw new Exception( "Folder '$path' does not exist.", ErrorCodes::NOT_FOUND );
		}
		try
		{
			// update the file that holds folder properties
			$properties = json_encode( $properties );
			$this->blobSvc->putBlobData( $this->storageContainer, $path, $properties );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $path
	 * @param bool   $force If true, delete folder content as well,
	 *                      otherwise return error when content present.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function deleteFolder( $path, $force = false )
	{
		try
		{
			$this->checkContainerForWrite(); // need to be able to write to storage
			error_log( $path );
			$blobs = $this->blobSvc->listBlobs( $this->storageContainer, $path );
			if ( !empty( $blobs ) )
			{
				if ( ( 1 === count( $blobs ) ) && ( 0 === strcasecmp( $path, $blobs[0]['name'] ) ) )
				{
					// only self properties blob
				}
				else
				{
					if ( !$force )
					{
						throw new Exception( "Folder '$path' contains other files or folders." );
					}
					foreach ( $blobs as $blob )
					{
						$this->blobSvc->deleteBlob( $this->storageContainer, $blob['name'] );
					}
				}
			}
			$this->blobSvc->deleteBlob( $this->storageContainer, $path );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param array  $folders
	 * @param string $root
	 * @param bool   $force If true, delete folder content as well,
	 *                      otherwise return error when content present.
	 *
	 * @throws Exception
	 * @return array
	 */
	public function deleteFolders( $folders, $root = '', $force = false )
	{
		$root = FileUtilities::fixFolderPath( $root );
		foreach ( $folders as $key => $folder )
		{
			try
			{
				// path is full path, name is relative to root, take either
				if ( isset( $folder['path'] ) )
				{
					$path = $folder['path'];
				}
				elseif ( isset( $folder['name'] ) )
				{
					$path = $root . $folder['name'];
				}
				else
				{
					throw new Exception( 'No path or name found for folder in delete request.' );
				}
				if ( !empty( $path ) )
				{
					$this->deleteFolder( $path, $force );
				}
				else
				{
					throw new Exception( 'No path or name found for folder in delete request.' );
				}
			}
			catch ( Exception $ex )
			{
				// error whole batch here?
				$folders[$key]['error'] = array( 'message' => $ex->getMessage(), 'code' => $ex->getCode() );
			}
		}

		return $folders;
	}

	/**
	 * @param $path
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function fileExists( $path )
	{
		try
		{
			if ( $this->blobSvc->containerExists( $this->storageContainer ) )
			{
				if ( $this->blobSvc->blobExists( $this->storageContainer, $path ) )
				{
					return true;
				}
			}
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}

		return false;
	}

	/**
	 * @param string $path
	 * @param string $local_file
	 * @param bool   $content_as_base
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getFileContent( $path, $local_file = '', $content_as_base = true )
	{
		try
		{
			if ( !$this->blobSvc->blobExists( $this->storageContainer, $path ) )
			{
				throw new Exception( "File '$path' does not exist in storage.", ErrorCodes::NOT_FOUND );
			}
			if ( !empty( $local_file ) )
			{
				// write to local or temp file
				$this->blobSvc->getBlobAsFile( $this->storageContainer, $path, $local_file );

				return '';
			}
			else
			{
				// get content as raw or encoded as base64 for transport
				$data = $this->blobSvc->getBlobData( $this->storageContainer, $path );
				if ( $content_as_base )
				{
					$data = base64_encode( $data );
				}

				return $data;
			}
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $path
	 * @param bool   $include_content
	 * @param bool   $content_as_base
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getFileProperties( $path, $include_content = false, $content_as_base = true )
	{
		try
		{
			if ( !$this->blobSvc->blobExists( $this->storageContainer, $path ) )
			{
				throw new Exception( "File '$path' does not exist in storage.", ErrorCodes::NOT_FOUND );
			}
			$blob = $this->blobSvc->listBlob( $this->storageContainer, $path );
			$shortName = FileUtilities::getNameFromPath( $path );
			$blob['path'] = $path;
			$blob['name'] = $shortName;
			if ( $include_content )
			{
				$data = $this->blobSvc->getBlobData( $this->storageContainer, $path );
				if ( $content_as_base )
				{
					$data = base64_encode( $data );
				}
				$blob['content'] = $data;
			}

			return $blob;
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $path
	 *
	 * @return void
	 * @throws Exception
	 */
	public function streamFile( $path )
	{
		try
		{
			$this->blobSvc->streamBlob( $this->storageContainer, $path, array() );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $path
	 *
	 * @return void
	 * @throws Exception
	 */
	public function downloadFile( $path )
	{
		try
		{
			$params = array( 'disposition' => 'attachment' );
			$this->blobSvc->streamBlob( $this->storageContainer, $path, $params );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string  $path
	 * @param string  $content
	 * @param boolean $content_is_base
	 * @param bool    $check_exist
	 *
	 * @return void
	 * @throws Exception
	 */
	public function writeFile( $path, $content, $content_is_base = false, $check_exist = false )
	{
		// does this file already exist?
		if ( $this->fileExists( $path ) )
		{
			if ( ( $check_exist ) )
			{
				throw new Exception( "File '$path' already exists.", ErrorCodes::BAD_REQUEST );
			}
		}
		// does this folder's parent exist?
		$parent = FileUtilities::getParentFolder( $path );
		if ( !empty( $parent ) && ( !$this->folderExists( $parent ) ) )
		{
			throw new Exception( "Folder '$parent' does not exist.", ErrorCodes::NOT_FOUND );
		}
		try
		{
			// create the file
			$this->checkContainerForWrite(); // need to be able to write to storage
			if ( $content_is_base )
			{
				$content = base64_decode( $content );
			}
			$ext = FileUtilities::getFileExtension( $path );
			$mime = FileUtilities::determineContentType( $ext, $content );
			$this->blobSvc->putBlobData( $this->storageContainer, $path, $content, $mime );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param        $path
	 * @param string $local_path
	 * @param bool   $check_exist
	 *
	 * @return void
	 * @throws Exception
	 */
	public function moveFile( $path, $local_path, $check_exist = true )
	{
		// does local file exist?
		if ( !file_exists( $local_path ) )
		{
			throw new Exception( "File '$local_path' does not exist.", ErrorCodes::NOT_FOUND );
		}
		// does this file already exist?
		if ( $this->fileExists( $path ) )
		{
			if ( ( $check_exist ) )
			{
				throw new Exception( "File '$path' already exists.", ErrorCodes::BAD_REQUEST );
			}
		}
		// does this file's parent folder exist?
		$parent = FileUtilities::getParentFolder( $path );
		if ( !empty( $parent ) && ( !$this->folderExists( $parent ) ) )
		{
			throw new Exception( "Folder '$parent' does not exist.", ErrorCodes::NOT_FOUND );
		}
		try
		{
			// create the file
			$this->checkContainerForWrite(); // need to be able to write to storage
			$ext = FileUtilities::getFileExtension( $path );
			$mime = FileUtilities::determineContentType( $ext, '', $local_path );
			$this->blobSvc->putBlobFromFile( $this->storageContainer, $path, $local_path, $mime );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param string $dest_path
	 * @param string $src_path
	 * @param bool   $check_exist
	 *
	 * @return void
	 * @throws Exception
	 */
	public function copyFile( $dest_path, $src_path, $check_exist = false )
	{
		// does this file already exist?
		if ( !$this->fileExists( $src_path ) )
		{
			throw new Exception( "File '$src_path' does not exist.", ErrorCodes::NOT_FOUND );
		}
		if ( $this->fileExists( $dest_path ) )
		{
			if ( ( $check_exist ) )
			{
				throw new Exception( "File '$dest_path' already exists.", ErrorCodes::BAD_REQUEST );
			}
		}
		// does this file's parent folder exist?
		$parent = FileUtilities::getParentFolder( $dest_path );
		if ( !empty( $parent ) && ( !$this->folderExists( $parent ) ) )
		{
			throw new Exception( "Folder '$parent' does not exist.", ErrorCodes::NOT_FOUND );
		}
		try
		{
			// create the file
			$this->checkContainerForWrite(); // need to be able to write to storage
			$this->blobSvc->copyBlob( $this->storageContainer, $dest_path, $this->storageContainer, $src_path );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param $path
	 *
	 * @return void
	 * @throws Exception
	 */
	public function deleteFile( $path )
	{
		try
		{
			$this->blobSvc->deleteBlob( $this->storageContainer, $path );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param array  $files
	 * @param string $root
	 *
	 * @return array
	 */
	public function deleteFiles( $files, $root = '' )
	{
		$root = FileUtilities::fixFolderPath( $root );
		foreach ( $files as $key => $file )
		{
			try
			{
				// path is full path, name is relative to root, take either
				if ( isset( $file['path'] ) )
				{
					$path = $file['path'];
				}
				elseif ( isset( $file['name'] ) )
				{
					$path = $root . $file['name'];
				}
				else
				{
					throw new Exception( 'No path or name found for file in delete request.', ErrorCodes::BAD_REQUEST );
				}
				if ( !empty( $path ) )
				{
					$this->deleteFile( $path );
				}
				else
				{
					throw new Exception( 'No path or name found for file in delete request.', ErrorCodes::BAD_REQUEST );
				}
			}
			catch ( Exception $ex )
			{
				// error whole batch here?
				$files[$key]['error'] = array( 'message' => $ex->getMessage(), 'code' => $ex->getCode() );
			}
		}

		return $files;
	}

	/**
	 * @param string          $path
	 * @param null|ZipArchive $zip
	 * @param string          $zipFileName
	 * @param bool            $overwrite
	 *
	 * @throws Exception
	 * @return string Zip File Name created/updated
	 */
	public function getFolderAsZip( $path, $zip = null, $zipFileName = '', $overwrite = false )
	{
		$path = FileUtilities::fixFolderPath( $path );
		$delimiter = '';
		try
		{
			if ( $this->blobSvc->containerExists( $this->storageContainer ) )
			{
				throw new Exception( "Can not find storage container for folder zip operation." );
			}
			$needClose = false;
			if ( !isset( $zip ) )
			{
				$needClose = true;
				$zip = new ZipArchive();
				if ( empty( $zipFileName ) )
				{
					$temp = FileUtilities::getNameFromPath( $path );
					if ( empty( $temp ) )
					{
						$temp = $this->storageContainer;
					}
					$tempDir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
					$zipFileName = $tempDir . $temp . '.zip';
				}
				if ( true !== $zip->open( $zipFileName, ( $overwrite ? ZipArchive::OVERWRITE : ZipArchive::CREATE ) ) )
				{
					throw new Exception( "Can not create zip file for directory '$path'." );
				}
			}
			$results = $this->blobSvc->listBlobs( $this->storageContainer, $path, $delimiter );
			foreach ( $results as $blob )
			{
				$fullPathName = $blob['name'];
				$shortName = substr_replace( $fullPathName, '', 0, strlen( $path ) );
				if ( empty( $shortName ) )
				{
					continue;
				}
				error_log( $shortName );
				if ( '/' == substr( $fullPathName, strlen( $fullPathName ) - 1 ) )
				{
					// folders
					if ( !$zip->addEmptyDir( $shortName ) )
					{
						throw new Exception( "Can not include folder '$shortName' in zip file." );
					}
				}
				else
				{
					// files
					$content = $this->blobSvc->getBlobData( $this->storageContainer, $fullPathName );
					if ( !$zip->addFromString( $shortName, $content ) )
					{
						throw new Exception( "Can not include file '$shortName' in zip file." );
					}
				}
			}
			if ( $needClose )
			{
				$zip->close();
			}

			return $zipFileName;
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 * @param            $path
	 * @param ZipArchive $zip
	 * @param bool       $clean
	 * @param string     $drop_path
	 *
	 * @return array
	 * @throws Exception
	 */
	public function extractZipFile( $path, $zip, $clean = false, $drop_path = '' )
	{
		if ( ( $clean ) )
		{
			try
			{
				// clear out anything in this directory
				$blobs = $this->blobSvc->listBlobs( $this->storageContainer, $path );
				if ( !empty( $blobs ) )
				{
					foreach ( $blobs as $blob )
					{
						if ( ( 0 !== strcasecmp( $path, $blob['name'] ) ) )
						{ // not folder itself
							$this->blobSvc->deleteBlob( $this->storageContainer, $blob['name'] );
						}
					}
				}
			}
			catch ( Exception $ex )
			{
				throw new Exception( "Could not clean out existing directory $path.\n{$ex->getMessage()}" );
			}
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ )
		{
			try
			{
				$name = $zip->getNameIndex( $i );
				if ( empty( $name ) )
				{
					continue;
				}
				if ( !empty( $drop_path ) )
				{
					$name = str_ireplace( $drop_path, '', $name );
				}
				$fullPathName = $path . $name;
				$parent = FileUtilities::getParentFolder( $fullPathName );
				if ( !empty( $parent ) )
				{
					$this->createFolder( $parent, true, array(), false );
				}
				if ( '/' === substr( $fullPathName, -1 ) )
				{
					$this->createFolder( $fullPathName, true, array(), false );
				}
				else
				{
					$content = $zip->getFromIndex( $i );
					$this->writeFile( $fullPathName, $content );
				}
			}
			catch ( Exception $ex )
			{
				throw $ex;
			}
		}

		return array( 'folder' => array( 'name' => rtrim( $path, DIRECTORY_SEPARATOR ), 'path' => $path ) );
	}
}