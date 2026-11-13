<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Configuration\PlatformSettings;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;

/**
 * Apariencia pública de la plataforma.
 *
 * Expone únicamente el tema visual elegido en Administración → Apariencia.
 * No requiere autenticación: la página pública también debe respetarlo.
 */
final class AppearanceController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionIndex(): array
    {
        $theme = PlatformSettings::get(Yii::$app->db, 'appearance.theme');
        $allowed = ['light', 'dark', 'aurora', 'aurora-dark'];

        Yii::$app->response->headers->set('Cache-Control', 'public, max-age=60');

        return [
            'theme' => in_array($theme, $allowed, true) ? $theme : 'light',
        ];
    }
}
