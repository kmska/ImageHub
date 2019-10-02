# ImageHub


# Usage

This project contains a ResourceSpace plugin to generate Tiled Pyramidal TIFF files when uploading a new image.

In order to make use of this plugin, the iiif_ptif/ folder should be copied to the plugins/ folder of your ResourceSpace installation and activated by the system administrator (System -> Manage plugins, under 'System').

The following lines should also be added to the configuration file of your ResourceSpace installation (include/config.php):

```
# 'name' and 'target_extension' must always be IIIF_PTIF,
# these are required by the ptif_files plugin to work properly.
$image_alternatives[0]['name']              = 'IIIFPTIF';
$image_alternatives[0]['target_extension']  = 'IIIFPTIF';
$image_alternatives[0]['source_extensions'] = 'jpg,png,tif,psb,psd';
$image_alternatives[0]['source_params']     = '';
$image_alternatives[0]['filename']          = 'IIIF_Tiled_Pyramidal_TIFF';
$image_alternatives[0]['params']            = '-define tiff:tile-geometry=256x256 -compress jpeg';
$image_alternatives[0]['icc']               = false;

# This must be set to NULL in order to fix a bug within ResourceSpace
# where resource files are not properly deleted if this value is set to anything other than NULL.
# This bug resides in include/resource_functions.php:2015.
$resource_deletion_state = NULL;
```
