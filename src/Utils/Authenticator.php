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
    public static function authenticate($adfsRequirement)
    {
        $auth = new Simple('default-sp');
        if ($auth->isAuthenticated()) {
            return Authenticator::isAllowed($auth->getAttributes(), $adfsRequirement);
        } else {
            $auth->requireAuth();
            if ($auth->isAuthenticated()) {
                return Authenticator::isAllowed($auth->getAttributes(), $adfsRequirement);
            } else {
                return false;
            }
        }
    }

    public static function isAllowed($attributes, $adfsRequirement)
    {
        $allowed = false;
        foreach ($attributes as $key => $values) {
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
    }
}