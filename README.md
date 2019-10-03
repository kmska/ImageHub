# ImageHub

This project contains a ResourceSpace plugin to generate Tiled Pyramidal TIFF files when uploading a new image.

## Requirements

This project requires following dependencies:
* ResourceSpace >= 9.1
* convert or vips, depending on your preferred image conversion tool

# Usage

In order to make use of this plugin, the iiif_ptif/ folder should be copied to the plugins/ folder of your ResourceSpace installation and activated by the system administrator (System -> Manage plugins, under 'System'). Also make sure that the webserver (www-data or apache2) has full access to this plugin folder.

The following lines should be added to the configuration file of your ResourceSpace installation (include/config.php):

```
# Config values required by iiif_ptif plugin

# Name of the folder where ptif files are stored (relative to the filestore/ directory)
# Must contain a leading and trailing slash
$iiif_ptif_filestore = '/iiif_ptif/';

# Command to preform image conversion to ptif. Recommended values: vips im_vips2tiff, convert (can be full path to executable)
$iiif_ptif_command = 'vips im_vips2tiff';

# Example arguments for convert: -define tiff:tile-geometry=256x256 -compress jpeg -quality 100
# Example arguments for vips: jpeg:100,tile:256x256,pyramid
$iiif_ptif_arguments = 'jpeg:100,tile:256x256,pyramid';

# Destination file prefix, only used by convert command
$iiif_ptif_prefix = 'ptif:';

```
