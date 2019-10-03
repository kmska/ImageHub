<?php
    # Generate Tiled Pyramidal TIFF files when uploading a new image

    # Return the path '/filestore/iiif/$ref.tif' to store PTIF files in when uploading a new image
    function getPtifFilePath($ref)
    {
        global $storagedir, $iiif_ptif_filestore;
        if(!file_exists($storagedir . $iiif_ptif_filestore)) {
            mkdir($storagedir . $iiif_ptif_filestore);
        }
        return $storagedir . $iiif_ptif_filestore . $ref . '.tif';
    }

    # Delete any generated PTIF files associated with this resource when the resource is being deleted
    function HookIiif_ptifAllBeforedeleteresourcefromdb($ref)
    {
      	$path = getPtifFilePath($ref);
		if(file_exists($path)) {
			unlink($path);
		}
    }

    function HookIiif_ptifAllUploadfilesuccess($resourceId)
    {
        global $lang, $iiif_ptif_command, $iiif_ptif_arguments;
        $extension = sql_value("select file_extension value from resource where ref = '" . escape_check($resourceId) . "'", 'tif');
        $sourcePath = get_resource_path($resourceId, true, '', true, $extension);
        $destPath = getPtifFilePath($resourceId);

        if(strpos($iiif_ptif_command, 'vips') > -1) {
            global $iiif_ptif_options;
            $command = $iiif_ptif_command . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($destPath) . ':' . $iiif_ptif_arguments;
        } else {
            global $iiif_ptif_prefix;
            $command = $iiif_ptif_command . ' ' . $iiif_ptif_arguments . ' ' . $iiif_ptif_prefix . escapeshellarg($sourcePath) . ' ' . escapeshellarg($destPath);
        }

        $output = run_command($command);

        log_activity($lang['resourcetypefieldreordered'],LOG_CODE_REORDERED,'Path = ' . $sourcePath . ', dest ' . $destPath,'resource_type_field','order_by');

        log_activity($lang['resourcetypefieldreordered'],LOG_CODE_REORDERED,'Command = ' . $command,'resource_type_field','order_by');
    }




?>
