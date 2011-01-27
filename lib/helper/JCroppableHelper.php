<?php

/**
 * gets the image src of a JCroppable Image based on the filename stored in the table, the model and the desired size
 * 
 * Useful for when you have an array representation of the model you need an image from rather than an object
 *
 * @param string $file_name
 * @param string $model
 * @param string $size
 * @return string 
 */

function get_jcroppable_image_src($file_name, $model, $size='thumb')
{
  if($file_name instanceof sfOutputEscaper){
    $file_name = $file_name->getRawValue();
  }
  $config = sfConfig::get('app_sfDoctrineJCroppablePlugin_models');
  $webDir = str_replace('\\', '/', sfConfig::get('sf_web_dir'));

  

  $basePath = str_replace($webDir . '/', '', sfConfig::get('sf_upload_dir'));

  if (!empty($config[$model]['directory'])) {

    $relativePath = $config[$model]['directory'];

  } else {

    $relativePath = 'images' . DIRECTORY_SEPARATOR . $model;

  }
  $extensionPosition = strrpos($file_name, '.');
  $stub = substr($file_name, 0, $extensionPosition);

  $image = str_replace($stub, $stub . '_' . $size, $file_name);

  $fileSrc = '/'.$basePath.'/' . $relativePath . '/' .$image;
  


  return $fileSrc;

}

