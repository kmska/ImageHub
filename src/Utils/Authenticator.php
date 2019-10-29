<?php
/**
 * Created by PhpStorm.
 * User: mike
 * Date: 10/29/19
 * Time: 4:44 PM
 */

namespace App\Utils;


use SimpleSAML\Auth\Simple;

class Authenticator
{
    public static function isAuthenticated($adfsRequirement)
    {
        $auth = new Simple('default-sp');
        $auth->requireAuth();

        if ($auth->isAuthenticated()) {
            $allowed = false;
            foreach ($auth->getAttributes() as $key => $values) {
                if ($adfsRequirement['key'] == $key) {
                    foreach ($values as $value) {
                        if ($adfsRequirement['value'] == $value) {
                            $allowed = true;
                            break;
                        }
                    }
                }
            }
            return $allowed;
        } else {
            return false;
        }
    }
}