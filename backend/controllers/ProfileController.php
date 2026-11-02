<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnprocessableEntityHttpException;

final class ProfileController extends Controller
{
    public $enableCsrfValidation = false;
    public function behaviors(): array
    {
        return ['contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]]];
    }

    public function actionView(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $row = Yii::$app->db->createCommand(
            'SELECT u.public_id, u.email, u.phone_e164, u.preferred_language, u.status,
                    p.first_name, p.last_name, p.middle_name, p.date_of_birth,
                    rc.iso2 AS residence_country_code, nc.iso2 AS nationality_country_code,
                    p.address_line, p.city, p.postal_code
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             LEFT JOIN {{%countries}} rc ON rc.id = p.residence_country_id
             LEFT JOIN {{%countries}} nc ON nc.id = p.nationality_country_id
             WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($row === false) throw new NotFoundHttpException('Profile not found');
        return $row;
    }

    public function actionUpdate(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $p = Yii::$app->request->bodyParams;
        if (!is_array($p)) throw new BadRequestHttpException('JSON object body is required');
        foreach (['first_name', 'last_name'] as $field) {
            if (!is_string($p[$field] ?? null) || trim($p[$field]) === '' || mb_strlen(trim($p[$field])) > 100) {
                throw new UnprocessableEntityHttpException("Invalid {$field}");
            }
        }
        $phone = isset($p['phone_e164']) && $p['phone_e164'] !== '' ? trim((string)$p['phone_e164']) : null;
        if ($phone !== null && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) throw new UnprocessableEntityHttpException('Phone must use E.164 format');
        $date = $p['date_of_birth'] ?? null;
        if ($date !== null && $date !== '' && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > gmdate('Y-m-d'))) {
            throw new UnprocessableEntityHttpException('Invalid date of birth');
        }
        $db = Yii::$app->db;
        $countryId = static function (?string $code) use ($db): ?int {
            if ($code === null || $code === '') return null;
            $id = $db->createCommand('SELECT id FROM {{%countries}} WHERE iso2 = :code AND is_active = 1 LIMIT 1', [':code' => strtoupper($code)])->queryScalar();
            return $id === false ? null : (int)$id;
        };
        $residence = $countryId(isset($p['residence_country_code']) ? (string)$p['residence_country_code'] : null);
        $nationality = $countryId(isset($p['nationality_country_code']) ? (string)$p['nationality_country_code'] : null);
        if (!empty($p['residence_country_code']) && $residence === null || !empty($p['nationality_country_code']) && $nationality === null) {
            throw new UnprocessableEntityHttpException('Unsupported country');
        }
        foreach (['middle_name' => 100, 'address_line' => 255, 'city' => 100, 'postal_code' => 20] as $field => $max) {
            if (isset($p[$field]) && (!is_string($p[$field]) || mb_strlen(trim($p[$field])) > $max)) throw new UnprocessableEntityHttpException("Invalid {$field}");
        }
        $tx = $db->beginTransaction();
        try {
            $db->createCommand()->update('{{%users}}', ['phone_e164' => $phone], ['id' => $userId])->execute();
            $db->createCommand()->upsert('{{%user_profiles}}', [
                'user_id' => $userId, 'first_name' => trim($p['first_name']), 'last_name' => trim($p['last_name']),
                'middle_name' => $p['middle_name'] ?? null, 'date_of_birth' => $date ?: null,
                'residence_country_id' => $residence, 'nationality_country_id' => $nationality,
                'address_line' => $p['address_line'] ?? null, 'city' => $p['city'] ?? null, 'postal_code' => $p['postal_code'] ?? null,
            ], [
                'first_name' => trim($p['first_name']), 'last_name' => trim($p['last_name']),
                'middle_name' => $p['middle_name'] ?? null, 'date_of_birth' => $date ?: null,
                'residence_country_id' => $residence, 'nationality_country_id' => $nationality,
                'address_line' => $p['address_line'] ?? null, 'city' => $p['city'] ?? null, 'postal_code' => $p['postal_code'] ?? null,
            ])->execute();
            $tx->commit();
        } catch (\Throwable $e) { if ($tx->getIsActive()) $tx->rollBack(); throw $e; }
        return $this->actionView();
    }
}
