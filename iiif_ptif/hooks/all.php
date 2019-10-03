<?php
    # Generate Tiled Pyramidal TIFF files when uploading a new image
    # Should be defined in config.php as such, along with any necessary command line arguments:
    # $image_alternatives[0]['name']              = 'IIIFPTIF';
    # $image_alternatives[0]['target_extension']  = 'IIIFPTIF';

    # Return the path '/filestore/iiif/$ref.tif' to store PTIF files in when uploading a new image
    function getPtifFilePath($ref)
    {
        global $storagedir;
        if(!file_exists($storagedir . '/iiif_ptif/')) {
            mkdir($storagedir . '/iiif_ptif/');
        }
        return $storagedir . '/iiif_ptif/' . $ref . '.tif';
    }

    # Return the path prefixed with 'ptif:' to pass to ImageMagick's convert command to generated Tile Pyramidal TIFF files
	function HookIiif_ptifAllGet_resource_path_override($ref, $getfilepath, $size, $generate,
		$extension, $scramble, $page, $watermarked, $file_modified, $alternative, $includemodified)
	{
		if($extension == 'IIIFPTIF')
			return 'ptif:' . getPtifFilePath($ref);
		else
			return false;
	}

    # Filter the alternative files view to exclude the PTIF files
    # This removes them from both the resource view page and also the 'manage alternative files' area.
	function HookIiif_ptifAllGet_alternative_files_extra_sql($resource)
    {
	    return "and name <> 'IIIFPTIF'";
    }

    # Delete any generated PTIF files associated with this resource when the resource is being deleted
    function HookIiif_ptifAllBeforedeleteresourcefromdb($ref)
    {
       	$path = getPtifFilePath($ref);
		if(file_exists($path)) {
			unlink($path);
		}
    }

?>
