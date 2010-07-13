<?php

class sfValidatorImageJCroppable extends sfValidatorBase
{
  protected function configure($options = array(), $messages = array())
  {
    parent::configure($options, $messages);

    $this->addRequiredOption('fieldName');
    $this->addRequiredOption('fieldValue');

    $this->addMessage('badcrop', 'Please select a larger area to crop');
  }

  protected function doClean($values)
  {
    $fieldName = $this->getOption('fieldName');
    $fieldValue = $this->getOption('fieldValue');

    /**
     * If there was previously no image and none has been uploaded then pass
     */
    if (empty($fieldValue) && empty($values[$fieldName]))
    {
      return $values;
    }

    if ($values[$fieldName . '_x1'] == $values[$fieldName . '_x2'])
    {
      throw new sfValidatorError($this, 'badcrop');
    }
    
    if ($values[$fieldName . '_y1'] == $values[$fieldName . '_y2'])
    {
      throw new sfValidatorError($this, 'badcrop');
    }

    return $values;
  }
}