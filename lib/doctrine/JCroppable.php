<?php

class Doctrine_Template_JCroppable extends Doctrine_Template
{
  /**
  * Array of ageable options
  *
  * @var string
  */
  protected $_options = array();
  
  private $editableImages = array();
  private $originalImages = array();
  
  /**
  * __construct
  *
  * @param string $array
  * @return void
  */
  public function __construct(array $options = array())
  { 
    $this->_options = Doctrine_Lib::arrayDeepMerge($this->_options, $options);
  }

  /**
   * Set table definition for JCroppable behavior
   *
   * @return void
   */
  public function setTableDefinition()
  {
    if (empty($this->_options['images']))
    {
      return false;
    }
    
    foreach ($this->_options['images'] as $fieldName) {
      $this->hasColumn($fieldName, 'string', 255, array('type' => 'string', 'length' => '255'));
      
      foreach (array('x1', 'y1', 'x2', 'y2') as $suffix) {
        $this->hasColumn($fieldName . '_' . $suffix, 'integer', null, array('type' => 'integer'));
      }
    }
    
    $this->addListener(new Doctrine_Template_Listener_JCroppable($this->_options));

    $this->createChildFormClass();
  }

  private function getTableNameCamelCase() {
    return preg_replace('/(?:^|_)(.?)/e',"strtoupper('$1')",
      $this->getInvoker()->getTable()->getTableName());
  }
  
  /**
   * Performs the following operations for a given image:
   *  1) removes any old files if the image has been re-uploaded
   *  2) creates a scaled down version for editing in the cropper if the image has been (re)uploaded
   *  3) creates the cropped versions of the image
   * 
   * This method is called from the listener if the image has been edited in any way
   *  
   * @param string $fieldName
   */
  public function updateImage($fieldName) {
    $oldValues = $this->getInvoker()->getModified(true);

    if (!empty($oldValues[$fieldName]) && $oldValues[$fieldName] != $this->getInvoker()->$fieldName)
    {
      $this->removeImages($fieldName, $oldValues[$fieldName]);
    }

    if (in_array($fieldName, array_keys($this->getInvoker()->getModified())))
    {
      $this->createEditableImage($fieldName);
    }
    
    $this->createCrops($fieldName);
  }
  
  /**
   * Takes a form and configures each image's widget.
   * 
   * This is one of only 2 methods the user needs to call manually (the other being configureJCropValidators)
   * Should be called from the form's configure() method
   * 
   * @param sfForm $form
   */
  public function configureJCropWidgets(sfForm $form, $formOptions = array()) {
    
    foreach ($this->_options['images'] as $fieldName) {
      if (!$imageConfig = $this->getImageConfig($fieldName))
      {
        continue;
      }
      
      $form->setWidget($fieldName, 
        new sfWidgetFormInputFileInputImageJCroppable(array(
          'invoker' => $this->getInvoker(),
          'image_field' => $fieldName,
          'image_ratio' => isset($imageConfig['ratio']) ? $imageConfig['ratio'] : false,
          'with_delete' => true,
          'file_src' => $this->getImageSrc($fieldName, 'editable'),
          'template'  => '%file%<br />%input%<br />%delete% %delete_label%',
          'form' => $form
        ))
      );
      
      foreach (array('x1', 'y1', 'x2', 'y2') as $suffix) {
        $id_stub = $form->getWidget($fieldName)->getIdStub();
        $widget = new sfWidgetFormInputHidden();
        $widget->setAttribute('id', $id_stub. '_' . $suffix);
        $form->setWidget($fieldName . '_' . $suffix, $widget);
      }
    }
  }
  
  /**
   * Takes a form and configures each image's widget.
   * 
   * This is one of only 2 methods the user needs to call manually (the other being configureJCropWidgets)
   * Should be called from the form's configure() method
   * 
   * @param sfForm $form
   */
  public function configureJCropValidators($form) {
    
    foreach ($this->_options['images'] as $fieldName) {
      
      $form->setValidator($fieldName . '_delete',  new sfValidatorPass());
      
      $form->setValidator($fieldName,
        new sfValidatorFile(array(
            'required'   => false,
            'path'       => $this->getImageDir(),
            'mime_types' => 'web_images',
          ),
          array('mime_types' => 'Unsupported image type (%mime_type%)')
        )
      );

      $form->mergePostValidator(new sfValidatorImageJCroppable(array(
        'fieldName' => $fieldName,
        'fieldValue' => $this->getInvoker()->$fieldName
        )));
    }

  }
  
  /**
   * Get the directory to store the images in by looking in the following places:
   * 
   *  1) table specific config
   *  2) global plugin config
   *  3) default location
   * 
   * @return string
   */
  private function getImageDir() {
    $config = $this->getModelsConfig();
    
    $basePath = sfConfig::get('sf_upload_dir');
    
    $tableName = $this->getTableNameCamelCase();

    if (!empty($config[$tableName]['directory'])) {
      
      $relativePath = $config[$tableName]['directory'];
      
    } else {
      
      $relativePath = $this->getImageDirDefault();
      
    }
    
    return $basePath . DIRECTORY_SEPARATOR . $relativePath;
  }
  
  /**
   * Generate's the default directory to store the model's images in (relative to uploads/)
   * 
   * @return string
   */
  private function getImageDirDefault() {
    return 'images' . DIRECTORY_SEPARATOR . $this->getTableNameCamelCase();
  }
  
  /**
   * Gets the model's image directory relative to the web root (sf_web_dir)
   * 
   * @return string
   */
  private function getImageDirWeb() {
    $webDir = str_replace('\\', '/', sfConfig::get('sf_web_dir'));
    $imageDir = str_replace('\\', '/', $this->getImageDir());

    return (string)str_replace($webDir . '/', '', $imageDir);
  }
  
  /**
   * Gets the given field's absolute editable image path, and warns if the directory
   *  doesn't exist or is not writable
   * 
   * @param string $fieldName
   * @return string
   */
  public function getImageSrc($fieldName, $size = 'thumb') {
    $fileDir = $this->getImageDirWeb();
    
    if (!$this->checkDirectoryExists($fileDir)) {
      $this->sfLog("image upload directory <strong>$fileDir</strong> doesn't exist");
    }
    if (!$this->checkDirectoryWritable($fileDir)) {
      $this->sfLog("image upload directory <strong>$fileDir</strong> is not writable");
    }
    
    //if there is no pic do not return the broken markup
    if ($this->getImageFromName($fieldName, $size) != false)
    {
      $fileSrc = '/' . $fileDir . '/' . $this->getImageFromName($fieldName, $size);
      return $fileSrc;
    }
    else
    {
      return false;
    }
  }

  public function checkDirectoryExists($fileDir){
    $root = sfConfig::get('sf_root_dir');
    return file_exists($root.'/web/'.$fileDir);
  }

  public function checkDirectoryWritable($fileDir){
    $root = sfConfig::get('sf_root_dir');
    return is_writable($root.'/web/'.$fileDir);
  }

  /**
   * Returns an img tag for the specified image field & size (default thumb)
   *
   * @param string $fieldName
   * @param string $size
   * @param array $attributes
   * @return string
   */
  public function getImageTag($fieldName, $size = 'thumb', $attributes = array())
  {
    if ($this->getImageSrc($fieldName, $size) != false)
    {
      return tag(
        'img',
        array_merge(
        $attributes,
        array('src' => $this->getImageSrc($fieldName, $size))
        )
      );
    }
    else
    {
      return '';
    }
  }
  
  /**
   * Takes the original image, adds and padding to it and creates an editable version
   *  for use in the cropper
   *  
   * @param string $fieldName
   */
  private function createEditableImage($fieldName) {
    $imageConfig = $this->getImageConfig($fieldName);
    /**
     * Get the filenames for the editoable and original versions of the image
     */
    $original = $this->getImageFromName($fieldName, 'original');
    $editable = $this->getImageFromName($fieldName, 'editable');

    if (empty($original) || empty($editable))
    {
      return false;
    }
    
    $dir = $this->getImageDir();

    $from = $dir . DIRECTORY_SEPARATOR . $editable;
    $to = $dir . DIRECTORY_SEPARATOR . $original;

    /**
     * If we can't find the image
     */
    if (!file_exists($from))
    {
      /**
       * Check if we're loading data rather than saving via the admin interface
       *  and look for the image in the data/images dir
       */
      if (!sfContext::hasInstance() && file_exists('data/images/' . $editable))
      {
        print('Found data/images/' . $editable . "... ");
        if (is_writable($dir) && (!file_exists($to) || is_writable($to)))
        {
          copy('data/images/' . $editable, $to);
          print("copied\n");
        }
        else
        {
          print("Don't have permission to copy to $to\n");
        }
      }
      /**
       * We don't have the original for some reason and we can't find a data
       * copy so best just return
       */
      else
      {
        return;
      }
    }
    else
    {
      /**
       * Move the new image to be named as the original
       */
      rename($from, $to);
    }

    /**
     * Check we have permission to save the images
     */
    if (!is_writable($dir)
          || (file_exists($dir . DIRECTORY_SEPARATOR . $editable) && !is_writable($dir . DIRECTORY_SEPARATOR . $editable))
          || (file_exists($dir . DIRECTORY_SEPARATOR . $original) && !is_writable($dir . DIRECTORY_SEPARATOR . $original)))
    {
      $this->sfLog("Can't save image(s). Maybe it/they exist already or the dir doesn't have write permission
        " . $dir . DIRECTORY_SEPARATOR . $editable . "
        " . $dir . DIRECTORY_SEPARATOR . $original);
      return;
    }
    
    /**
     * Load the original and resize it for the editable version
     */
    $img = new sfImage($dir . DIRECTORY_SEPARATOR . $original);
    
    if (sfContext::hasInstance() && isset($imageConfig['padding'])) {
      $img = $this->addPadding($img, $imageConfig['padding']);
      
      $img->saveAs($dir . DIRECTORY_SEPARATOR . $original);
    }

    $img->resize(400, null);
    $img->saveAs($dir . DIRECTORY_SEPARATOR . $editable);


    if ($imageConfig['ratio'])
    {
      $ratioOriginal = $img->getWidth() / $img->getHeight();
      $ratioDesired = $imageConfig['ratio'];

      if ($ratioDesired >= $ratioOriginal)
      {
        $cropWidth = $img->getWidth();
        $cropHeight = $cropWidth / $ratioDesired;
      }
      else
      {
        $cropHeight = $img->getHeight();
        $cropWidth = $cropHeight * $ratioDesired;
      }
    }
    else
    {
      $cropWidth = $img->getWidth();
      $cropHeight = $img->getHeight();
    }

    $cropLeft = $this->getLeftAlignment($fieldName, $cropWidth, $img->getWidth());
    $cropTop = $this->getTopAlignment($fieldName, $cropHeight, $img->getHeight());

    $this->getInvoker()->{$fieldName . '_x1'} = $cropLeft;
    $this->getInvoker()->{$fieldName . '_y1'} = $cropTop;
    $this->getInvoker()->{$fieldName . '_x2'} = $cropLeft + $cropWidth;
    $this->getInvoker()->{$fieldName . '_y2'} = $cropTop + $cropHeight;
  }

  private function getLeftAlignment($fieldName, $innerWidth, $outerWidth)
  {
    $config = $this->getAlignmentConfig();
    $fieldConfig = $this->getImageConfig($fieldName);

    $alignment = isset($fieldConfig['defaultAlignment'])
      ? $fieldConfig['defaultAlignment'][0]
      : ($config
            ? $config[0]
            : 'left'
            );

    $left = 0;

    if ($alignment == 'right')
    {
      $left = $outerWidth - $innerWidth;
    }
    else if (in_array($alignment, array('centre', 'center', 'middle')))
    {
      $left = (int)(($outerWidth - $innerWidth) / 2);
    }

    return $left;
  }

  private function getTopAlignment($fieldName, $innerHeight, $outerHeight)
  {
    $config = $this->getAlignmentConfig();
    $fieldConfig = $this->getImageConfig($fieldName);

    $alignment = isset($fieldConfig['defaultAlignment'])
      ? $fieldConfig['defaultAlignment'][1]
      : ($config
            ? $config[1]
            : 'left'
            );

    $top = 0;

    if ($alignment == 'bottom')
    {
      $top = $outerHeight - $innerHeight;
    }
    else if (in_array($alignment, array('centre', 'center', 'middle')))
    {
      $top = (int)(($outerHeight - $innerHeight) / 2);
    }

    return $top;
  }
  
  /**
   * Adds any padding to the given image using the supplied padding config
   * 
   * @param $img
   * @param array $padding
   * @return $img
   */
  private function addPadding($img, $padding) {
    if (!$padding) {
      return $img;
    }
    
    if (isset($padding['percent']) && is_numeric($padding['percent'])) {
      
      $width = $img->getWidth() * (1 + ($padding['percent'] / 100));
      $height = $img->getHeight() * (1 + ($padding['percent'] / 100));
      
    } else if (isset($padding['pixels']) && is_numeric($padding['pixels'])) {
      
      /**
       * We multiply by 2 to account for padding on both edges
       */
      $width = $img->getWidth() + (2 * $padding['pixels']);
      $height = $img->getHeight() + (2 * $padding['pixels']);
      
    } else {
      
      return $img;
      
    }
    
    $canvas = new sfImage();
    $canvas
      ->fill(0, 0, isset($padding['color']) ? $padding['color'] : '#ffffff')
      ->resize($width, $height)
      ->overlay($img, 'center');
    
    return $canvas;
  }
  
  /**
   * Gets the filename for the given image field and size. Uses the current field value, 
   *  but can be overriden by passing a different value as the 3rd parameter
   *  
   * @param $fieldName
   * @param $size
   * @param $editable
   * @return $image
   */
  private function getImageFromName($fieldName, $size = 'editable', $editable = null) {
    if (!$imageConfig = $this->getImageConfig($fieldName)) {
      return false;
    }
    
    if ($editable == null) {
      $editable = $this->getInvoker()->$fieldName;
    }
    
    if ($size == 'editable' || (!isset($imageConfig['sizes'][$size]) && $size != 'original')) {
      return $editable;
    }
    
    $extensionPosition = strrpos($editable, '.');
    $stub = substr($editable, 0, $extensionPosition);
    
    $image = str_replace($stub, $stub . '_' . $size, $editable);
    
    return $image;
  }
  
  /**
   * Creates the cropped version of the given field's images
   * 
   * @param string $fieldName
   * @return bool
   */
  private function createCrops($fieldName) {
    if (!$imageConfig = $this->getImageConfig($fieldName)) {
      return false;
    }
    
    $this->loadImage($fieldName, 'editable');
    $this->loadImage($fieldName, 'original');
    
    foreach ($imageConfig['sizes'] as $size => $dims) {
      
      $this->createCropForSize($fieldName, $size);
      
    }
    
    return true;
  }
  
  /**
   * Loads either the editable or original version of the given image field
   * 
   * @param string $fieldName
   * @param string $version - editable or original 
   * @param $force - try to load the image even if there's no config for image
   */
  private function loadImage($fieldName, $version, $force = false) {
    $imageConfig = $this->getImageConfig($fieldName);
    
    if (!$this->getInvoker()->$fieldName || (!$imageConfig && !$force)) {
      return;
    }
    
    $this->{$version . 'Images'}[$fieldName] =
      new sfImage($this->getImageDir() . DIRECTORY_SEPARATOR . $this->getImageFromName($fieldName, $version));
  }
  
  /**
   * Creates the crop of the given field's image at the specified size
   * 
   * @param $fieldName
   * @param $size
   */
  private function createCropForSize($fieldName, $size) {
    if (!$imageConfig = $this->getImageConfig($fieldName)) {
      return false;
    }
    
    $this->loadImage($fieldName, 'original');
    $this->loadImage($fieldName, 'editable');

    if (empty($this->originalImages[$fieldName]) || empty($this->editableImages[$fieldName]))
    {
      return false;
    }
    
    $ratio = $this->originalImages[$fieldName]->getWidth() /
      $this->editableImages[$fieldName]->getWidth();
    
    $dims['x'] = (int)$this->getInvoker()->{$fieldName . '_x1'} * $ratio;
    $dims['y'] = (int)$this->getInvoker()->{$fieldName . '_y1'} * $ratio;
    $dims['w'] = (int)($this->getInvoker()->{$fieldName . '_x2'} * $ratio) - $dims['x'];
    $dims['h'] = (int)($this->getInvoker()->{$fieldName . '_y2'} * $ratio) - $dims['y'];
    
    $origCrop = $this->originalImages[$fieldName]
      ->crop($dims['x'], $dims['y'], $dims['w'], $dims['h']);

    $finalCrop = $origCrop->resize(
      $imageConfig['sizes'][$size]['width'],
      empty($imageConfig['ratio']) ?
        null :
        round($imageConfig['sizes'][$size]['width'] / $imageConfig['ratio']));
    
    $fullPath = $this->getImageDir() . DIRECTORY_SEPARATOR . $this->getImageFromName($fieldName, $size);
    
    $finalCrop->saveAs($fullPath);
  }
  
  /**
   * Removes all existing images for the given field, and the field's value
   *  can be overridden using the second parameter
   *  
   * @param $fieldName
   * @param $editable
   */
  private function removeImages($fieldName, $editable) {
    if (!$imageConfig = $this->getImageConfig($fieldName)) {
      return;
    }
    
    /**
     * Remove the editable & original images
     */
    foreach (array('editable', 'original') as $type) {
      $fullPath = $this->getImageDir() . DIRECTORY_SEPARATOR
        . $this->getImageFromName($fieldName, $type, $editable);
      
      if (file_exists($fullPath)) {
        unlink($fullPath);
      }
    }
    
    /**
     * Loop through the sizes and remove them
     */
    foreach ($imageConfig['sizes'] as $size => $dims) {
      
      $filename = $this->getImageFromName($fieldName, $size, $editable);
      
      $fullPath = $this->getImageDir() . DIRECTORY_SEPARATOR . $filename;
      
      if (file_exists($fullPath)) {
        unlink($fullPath);
      }
      
    }
  }

  private function getModelsConfig()
  {
    return sfConfig::get('app_sfDoctrineJCroppablePlugin_models');
  }

  private function getAlignmentConfig()
  {
    return sfConfig::get('app_sfDoctrineJCroppablePlugin_defaultAlignment');
  }
  
  /**
   * Get's the config for the given field's image
   * 
   * @param $fieldName
   * @return array
   */
  private function getImageConfig($fieldName) {
    $config = $this->getModelsConfig();
    
    if (!isset($config[$this->getTableNameCamelCase()]['images'][$fieldName])) {
      return array('sizes' => array(
        'thumb' => array('width' => 120),
        'main' => array('width' => 360)
      ));
    }
    
    return $config[$this->getTableNameCamelCase()]['images'][$fieldName];
  }
  
  /**
   * Get's the table name of the invoking model
   * 
   * @return string
   */
  private function getTableName() {
    return $this->getInvoker()->getTable()->getTableName();
  }

  private function createChildFormClass()
  {
    $tableName = $this->getTableNameCamelCase();

    $baseForm = $tableName . 'Form';
    $extendedForm = 'JCroppable' . $baseForm;
    
    if (!class_exists($tableName . 'Form') || class_exists($extendedForm))
    {
      return false;
    }

    $class = '
class ' . $extendedForm . ' extends ' . $baseForm . '
{

  public function configure()
  {
    $this->getObject()->configureJCropWidgets($this, $this->options);
    $this->getObject()->configureJCropValidators($this);

    parent::configure();
  }
}';
    
    eval($class);
  }

  protected function sfLog($message)
  {
    /**
     * Log to symfony debug bar
     */
    if (class_exists('sfContext') && sfContext::hasInstance())
    {
      sfContext::getInstance()->getLogger()->debug($message);
    }
  }
}
