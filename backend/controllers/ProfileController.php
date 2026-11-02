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
        if (!is_array($p)) {
            throw new BadRequestHttpException('JSON object body is required');
        }

        $db = Yii::$app->db;
        $current = $db->createCommand(
            'SELECT u.phone_e164,p.first_name,p.last_name,p.middle_name,p.date_of_birth,
                    p.residence_country_id,p.nationality_country_id,p.address_line,p.city,p.postal_code
             FROM {{%users}} u LEFT JOIN {{%user_profiles}} p ON p.user_id=u.id
             WHERE u.id=:id AND u.deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($current === false) {
            throw new NotFoundHttpException('Profile not found');
        }

        $allowed = [
            'first_name', 'last_name', 'middle_name', 'phone_e164', 'date_of_birth',
            'residence_country_code', 'nationality_country_code', 'address_line', 'city', 'postal_code',
        ];
        $unknown = array_diff(array_keys($p), $allowed);
        if ($unknown !== []) {
            throw new UnprocessableEntityHttpException('Unsupported profile fields: ' . implode(', ', $unknown));
        }

        $firstName = trim((string) ($p['first_name'] ?? $current['first_name'] ?? ''));
        $lastName = trim((string) ($p['last_name'] ?? $current['last_name'] ?? ''));
        if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === '' || mb_strlen($lastName) > 100) {
            throw new UnprocessableEntityHttpException('First name and last name are required (maximum 100 characters)');
        }

        $phone = array_key_exists('phone_e164', $p)
            ? (trim((string) $p['phone_e164']) !== '' ? trim((string) $p['phone_e164']) : null)
            : $current['phone_e164'];
        if ($phone !== null && !preg_match('/^\\+[1-9]\\d{7,14}$/', $phone)) {
            throw new UnprocessableEntityHttpException('Phone must use E.164 format');
        }
        if ($phone !== null && (int) $db->createCommand(
            'SELECT COUNT(*) FROM {{%users}} WHERE phone_e164=:phone AND id<>:id AND deleted_at IS NULL',
            [':phone' => $phone, ':id' => $userId]
        )->queryScalar() > 0) {
            throw new UnprocessableEntityHttpException('This phone number is already registered');
        }

        $date = array_key_exists('date_of_birth', $p) ? ($p['date_of_birth'] ?: null) : $current['date_of_birth'];
        if ($date !== null) {
            if (!is_string($date) || !preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $date, $parts)
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                || $date > gmdate('Y-m-d')) {
                throw new UnprocessableEntityHttpException('Invalid date of birth');
            }
        }

        $countryId = static function (?string $code) use ($db): ?int {
            if ($code === null || $code === '') {
                return null;
            }
            $id = $db->createCommand(
                'SELECT id FROM {{%countries}} WHERE iso2=:code AND is_active=1 LIMIT 1',
                [':code' => strtoupper($code)]
            )->queryScalar();
            return $id === false ? null : (int) $id;
        };

        $residence = array_key_exists('residence_country_code', $p)
            ? $countryId((string) $p['residence_country_code'])
            : ($current['residence_country_id'] !== null ? (int) $current['residence_country_id'] : null);
        $nationality = array_key_exists('nationality_country_code', $p)
            ? $countryId((string) $p['nationality_country_code'])
            : ($current['nationality_country_id'] !== null ? (int) $current['nationality_country_id'] : null);
        if ((array_key_exists('residence_country_code', $p) && !empty($p['residence_country_code']) && $residence === null)
            || (array_key_exists('nationality_country_code', $p) && !empty($p['nationality_country_code']) && $nationality === null)) {
            throw new UnprocessableEntityHttpException('Unsupported country');
        }

        $values = [
            'middle_name' => $current['middle_name'],
            'address_line' => $current['address_line'],
            'city' => $current['city'],
            'postal_code' => $current['postal_code'],
        ];
        foreach (['middle_name' => 100, 'address_line' => 255, 'city' => 100, 'postal_code' => 20] as $field => $max) {
            if (array_key_exists($field, $p)) {
                $value = $p[$field] === null ? null : trim((string) $p[$field]);
                if ($value !== null && mb_strlen($value) > $max) {
                    throw new UnprocessableEntityHttpException("Invalid {$field}");
                }
                $values[$field] = $value === '' ? null : $value;
            }
        }

        $tx = $db->beginTransaction();
        try {
            $db->createCommand()->update('{{%users}}', ['phone_e164' => $phone], ['id' => $userId])->execute();
            $profileValues = [
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'middle_name' => $values['middle_name'],
                'date_of_birth' => $date,
                'residence_country_id' => $residence,
                'nationality_country_id' => $nationality,
                'address_line' => $values['address_line'],
                'city' => $values['city'],
                'postal_code' => $values['postal_code'],
            ];
            $db->createCommand()->upsert('{{%user_profiles}}', $profileValues, $profileValues)->execute();

            $db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'user.profile.updated',
                'entity_type' => 'user_profile',
                'entity_id' => (string) $userId,
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
                'after_data' => new \\yii\\db\\JsonExpression([
                    'changed_fields' => array_values(array_intersect(array_keys($p), $allowed)),
                ]),
            ])->execute();
            $tx->commit();
        } catch (\\Throwable $exception) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            }
            throw $exception;
        }

        return $this->actionView();
    }}
