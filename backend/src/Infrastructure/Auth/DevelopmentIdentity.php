<?php

declare(strict_types=1);

namespace app\Infrastructure\Auth;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

final class DevelopmentIdentity extends BaseObject implements IdentityInterface
{
    public int $id;
    public string $publicId;

    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return null;
    }

    public function validateAuthKey($authKey)
    {
        return false;
    }
}
