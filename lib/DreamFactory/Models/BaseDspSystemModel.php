<?php
namespace DreamFactory\Models;

use Kisma\Core\Exceptions\StorageException;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Sql;

/**
 * BaseDspSystemModel.php
 * A base class for DSP system models
 *
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 * Copyright (c) 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Base Columns:
 *
 * @property integer $id
 * @property string  $created_date
 * @property string  $last_modified_date
 * @property integer $created_by_id
 * @property integer $last_modified_by_id
 *
 * Base Relations:
 *
 * @property User    $created_by
 * @property User    $last_modified_by
 */
abstract class BaseDspSystemModel extends BaseDspModel
{
	/**
	 * @return string the system database table name prefix
	 */
	public static function tableNamePrefix()
	{
		return 'df_sys_';
	}

	/**
	 * @return array relational rules.
	 */
	public function relations()
	{
		return array(
			'created_by'       => array( static::BELONGS_TO, 'User', 'created_by_id' ),
			'last_modified_by' => array( static::BELONGS_TO, 'User', 'last_modified_by_id' ),
		);
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 *
	 * @return \CActiveDataProvider the data provider that can return the models based on the search/filter conditions.
	 */
	public function search()
	{
		$_criteria = new \CDbCriteria;

		$_criteria->compare( 'id', $this->id );
		$_criteria->compare( 'created_date', $this->created_date, true );
		$_criteria->compare( 'last_modified_date', $this->last_modified_date, true );
		$_criteria->compare( 'created_by_id', $this->created_by_id );
		$_criteria->compare( 'last_modified_by_id', $this->last_modified_by_id );

		return new \CActiveDataProvider(
			$this,
			array(
				 'criteria' => $_criteria,
			)
		);
	}

	/**
	 * @param \CModelEvent $event
	 *
	 * @return void
	 */
	public function onBeforeValidate( $event )
	{
		try
		{
			$this->last_modified_by_id = \SessionManager::getCurrentUserId();

			if ( $this->isNewRecord )
			{
				$this->created_by_id = $this->last_modified_by_id;
			}
		}
		catch ( \Exception $_ex )
		{
			Log::error( 'Exception setting create/lmod user ID: ' . $_ex->getMessage() );
		}

		parent::onBeforeValidate( $event );
	}

	/**
	 * @param string $requested Comma-delimited list of requested fields
	 * @param array  $columns   Additional columns to add
	 * @param array  $hidden    Columns to hide from requested
	 *
	 * @return array
	 */
	public function getRetrievableAttributes( $requested, $columns = array(), $hidden = array() )
	{
		if ( empty( $requested ) )
		{
			// primary keys only
			return array( 'id' );
		}

		if ( static::ALL_ATTRIBUTES == $requested )
		{
			return array_merge(
				array(
					 'id',
					 'created_date',
					 'created_by_id',
					 'last_modified_date',
					 'last_modified_by_id'
				),
				$columns
			);
		}

		//	Remove the hidden fields
		$_columns = explode( ',', $requested );

		if ( !empty( $hidden ) )
		{
			foreach ( $_columns as $_index => $_column )
			{
				foreach ( $hidden as $_hide )
				{
					if ( 0 == strcasecmp( $_column, $_hide ) )
					{
						unset( $_columns[$_index] );
					}
				}
			}
		}

		return $_columns;
	}

	protected function _manyToOne( $id, $toTable, $toColumn, $rows = array() )
	{
		if ( empty( $id ) )
		{
			throw new \InvalidArgumentException( 'The id specified is invalid.' );
		}

		/** @var $_table BaseDspSystemModel */
		$_table = \SystemManager::getNewResource( $toTable );
		$_pk = $_table->getTableSchema()->primaryKey;
		$_tableName = $_table->tableName();

		$_sql = <<<SQL
SELECT
	{$_pk}, {$toColumn}
FROM
	{$_tableName}
WHERE
	{$toColumn} = :id
SQL;

		$_rows = Sql::query( $_sql, array( ':id' => $id, ), $_db );

		if ( empty( $_rows ) )
		{
			return;
		}

		foreach ( $_rows as $_row )
		{
			$_found = false;
			$_id = $_row[$_pk];

			foreach ( $many_records as $key => $item )
			{
				$assignId = Utilities::getArrayValue( $pkField, $item, '' );
				if ( $id == $assignId )
				{
					// found it, keeping it, so remove it from the list, as this becomes adds
					unset( $many_records[$key] );
					$found = true;
					continue;
				}
			}
			if ( !$found )
			{
				$toDelete[] = $id;
				continue;
			}
		}
	}

	/**
	 * @param string $one_id
	 * @param string $many_table
	 * @param string $many_field
	 * @param array  $many_records
	 *
	 * @throws Kisma\Core\Exceptions\StorageException
	 * @throws Exception
	 * @return void
	 */
	protected function assignManyToOne( $one_id, $many_table, $many_field, $many_records = array() )
	{
		if ( empty( $one_id ) )
		{
			throw new Exception( "The id can not be empty.", ErrorCodes::BAD_REQUEST );
		}

		try
		{
			$manyObj = SystemManager::getNewResource( $many_table );
			$pkField = $manyObj->tableSchema->primaryKey;
			$many_table = static::tableNamePrefix() . $many_table;
			// use query builder
			$command = Yii::app()->db->createCommand();
			$command->select( "$pkField,$many_field" );
			$command->from( $many_table );
			$command->where( "$many_field = :oid" );
			$maps = $command->queryAll( true, array( ':oid' => $one_id ) );
			$toDelete = array();
			foreach ( $maps as $map )
			{
				$id = Utilities::getArrayValue( $pkField, $map, '' );
				$found = false;
				foreach ( $many_records as $key => $item )
				{
					$assignId = Utilities::getArrayValue( $pkField, $item, '' );
					if ( $id == $assignId )
					{
						// found it, keeping it, so remove it from the list, as this becomes adds
						unset( $many_records[$key] );
						$found = true;
						continue;
					}
				}
				if ( !$found )
				{
					$toDelete[] = $id;
					continue;
				}
			}
			if ( !empty( $toDelete ) )
			{
				// simple update to null request
				$command->reset();
				$rows = $command->update( $many_table, array( $many_field => null ), array( 'in', $pkField, $toDelete ) );
				if ( 0 >= $rows )
				{
//					throw new Exception( "Record update failed for table '$many_table'." );
				}
			}
			if ( !empty( $many_records ) )
			{
				$toAdd = array();
				foreach ( $many_records as $item )
				{
					$itemId = Utilities::getArrayValue( $pkField, $item, '' );
					if ( !empty( $itemId ) )
					{
						$toAdd[] = $itemId;
					}
				}
				if ( !empty( $toAdd ) )
				{
					// simple update to null request
					$command->reset();
					$rows = $command->update( $many_table, array( $many_field => $one_id ), array( 'in', $pkField, $toAdd ) );
					if ( 0 >= $rows )
					{
//						throw new Exception( "Record update failed for table '$many_table'." );
					}
				}
			}
		}
		catch ( Exception $ex )
		{
			throw new Exception( "Error updating many to one assignment.\n{$ex->getMessage()}", $ex->getCode() );
		}
	}

	/**
	 * @param       $one_id
	 * @param       $many_table
	 * @param       $map_table
	 * @param       $one_field
	 * @param       $many_field
	 * @param array $many_records
	 *
	 * @throws Exception
	 * @return void
	 */
	protected function assignManyToOneByMap( $one_id, $many_table, $map_table, $one_field, $many_field, $many_records = array() )
	{
		if ( empty( $one_id ) )
		{
			throw new Exception( "The id can not be empty.", ErrorCodes::BAD_REQUEST );
		}
		$map_table = static::tableNamePrefix() . $map_table;
		try
		{
			$manyObj = SystemManager::getNewResource( $many_table );
			$pkManyField = $manyObj->tableSchema->primaryKey;
			$pkMapField = 'id';
			// use query builder
			$command = Yii::app()->db->createCommand();
			$command->select( $pkMapField . ',' . $many_field );
			$command->from( $map_table );
			$command->where( "$one_field = :id" );
			$maps = $command->queryAll( true, array( ':id' => $one_id ) );
			$toDelete = array();
			foreach ( $maps as $map )
			{
				$manyId = Utilities::getArrayValue( $many_field, $map, '' );
				$id = Utilities::getArrayValue( $pkMapField, $map, '' );
				$found = false;
				foreach ( $many_records as $key => $item )
				{
					$assignId = Utilities::getArrayValue( $pkManyField, $item, '' );
					if ( $assignId == $manyId )
					{
						// found it, keeping it, so remove it from the list, as this becomes adds
						unset( $many_records[$key] );
						$found = true;
						continue;
					}
				}
				if ( !$found )
				{
					$toDelete[] = $id;
					continue;
				}
			}
			if ( !empty( $toDelete ) )
			{
				// simple delete request
				$command->reset();
				$rows = $command->delete( $map_table, array( 'in', $pkMapField, $toDelete ) );
				if ( 0 >= $rows )
				{
//					throw new Exception( "Record delete failed for table '$map_table'." );
				}
			}
			if ( !empty( $many_records ) )
			{
				foreach ( $many_records as $item )
				{
					$itemId = Utilities::getArrayValue( $pkManyField, $item, '' );
					$record = array( $many_field => $itemId, $one_field => $one_id );
					// simple update request
					$command->reset();
					$rows = $command->insert( $map_table, $record );
					if ( 0 >= $rows )
					{
						throw new Exception( "Record insert failed for table '$map_table'." );
					}
				}
			}
		}
		catch ( Exception $ex )
		{
			throw new Exception( "Error updating many to one map assignment.\n{$ex->getMessage()}", $ex->getCode() );
		}
	}
}