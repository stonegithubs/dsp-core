<?php
/* @var $this SiteController */
/* @var $model InitAdminForm */
/* @var $form CActiveForm */

$this->pageTitle=Yii::app()->name . ' - Initialization';
$this->breadcrumbs=array(
	'Initialization',
);
?>

<h1>Initialization</h1>

<?php if(Yii::app()->user->hasFlash('init')): ?>

<div class="flash-success">
	<?php echo Yii::app()->user->getFlash('init'); ?>
</div>

<?php else: ?>

<p>
Your Document Services Platform needs a system administrator.<br/>
This admin will be a separate admin account just for this site, not your www.dreamfactory.com account.<br/>
More administrators and users can be added using the 'System Admin' application once this admin account is created.<br/>
<br/>
Click the 'Submit' button below to proceed.
</p>

<div class="form">

<?php $form=$this->beginWidget('CActiveForm', array(
	'id'=>'init-form',
	'enableClientValidation'=>true,
	'clientOptions'=>array(
		'validateOnSubmit'=>true,
	),
)); ?>

	<p class="note">Fields with <span class="required">*</span> are required.</p>

	<?php echo $form->errorSummary($model); ?>

	<div class="row">
		<?php echo $form->labelEx($model,'username'); ?>
		<?php echo $form->textField($model,'username'); ?>
		<?php echo $form->error($model,'username'); ?>
	</div>

    <div class="row">
        <?php echo $form->labelEx($model,'password'); ?>
        <?php echo $form->passwordField($model,'password'); ?>
        <?php echo $form->error($model,'password'); ?>
    </div>

    <div class="row">
        <?php echo $form->labelEx($model,'passwordRepeat'); ?>
        <?php echo $form->passwordField($model,'passwordRepeat'); ?>
        <?php echo $form->error($model,'passwordRepeat'); ?>
    </div>

    <div class="row">
   		<?php echo $form->labelEx($model,'email'); ?>
   		<?php echo $form->textField($model,'email'); ?>
   		<?php echo $form->error($model,'email'); ?>
   	</div>

    <div class="row">
   		<?php echo $form->labelEx($model,'firstName'); ?>
   		<?php echo $form->textField($model,'firstName'); ?>
   		<?php echo $form->error($model,'firstName'); ?>
   	</div>

    <div class="row">
        <?php echo $form->labelEx($model,'lastName'); ?>
        <?php echo $form->textField($model,'lastName'); ?>
        <?php echo $form->error($model,'lastName'); ?>
    </div>

    <div class="row">
        <?php echo $form->labelEx($model,'displayName'); ?>
        <?php echo $form->textField($model,'displayName'); ?>
        <?php echo $form->error($model,'displayName'); ?>
    </div>

    <div class="row buttons">
		<?php echo CHtml::submitButton('Submit'); ?>
	</div>

<?php $this->endWidget(); ?>

</div><!-- form -->

<?php endif; ?>