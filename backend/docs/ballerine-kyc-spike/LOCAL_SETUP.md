# Evaluación local aislada de Ballerine

Esta PoC ejecuta el ejemplo oficial de KYC manual-review fuera de los contenedores y del ciclo de vida de SEDERG_PAY. No se agrega una imagen Docker de Ballerine al compose principal porque el upstream documenta un monorepo Node/pnpm que levanta su propio workflow service, UI SDK y backoffice.

> **Alternativa recomendada para integración continua:** el repositorio incluye
> un simulador propio en Docker (`kyc-mock`) que implementa el contrato que
> consume el adaptador y emite webhooks firmados reales. Se levanta con
> `scripts/Enable-BallerineMock.ps1` y se documenta en
> [`docs/ballerine-kyc-mock.md`](../../../docs/ballerine-kyc-mock.md). Úsalo para
> probar sesión, webhook, idempotencia y revisión manual. Esta PoC con el
> monorepo upstream queda para validar el contrato contra Ballerine real.

## Requisitos

- Windows 10/11 con PowerShell.
- Git.
- Node.js 18 o 20 (el package.json upstream declara Node >=18 y <22).
- pnpm >=8.
- Docker Desktop con Docker Compose, necesario para los servicios auxiliares del ejemplo.

## Instalar

Desde la raíz de SEDERG_PAY:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\Install-BallerineKycLocal.ps1
```

El script clona Ballerine en `.local/ballerine`, registra el commit exacto instalado y ejecuta `pnpm install` y `pnpm monorepo:init`. No modifica el compose de SEDERG_PAY ni inicia el ejemplo.

## Iniciar el ejemplo

```powershell
.\scripts\Start-BallerineKycLocal.ps1
```

Puertos oficiales del ejemplo: API 3000, colección 5173 y backoffice 5137. El frontend actual de SEDERG_PAY también publica 5173; por tanto, detén temporalmente ese frontend si ocupa el puerto:

```powershell
docker compose stop frontend
```

Para reanudarlo:

```powershell
docker compose start frontend
```

No detengas MySQL ni el backend de SEDERG_PAY: la PoC todavía no está conectada a ellos. El ejemplo upstream utiliza configuración y servicios propios.

## Datos y seguridad

- Usa exclusivamente identidades ficticias.
- No cargues documentos, fotos, selfies, pasaportes, direcciones ni datos de clientes reales.
- No configures claves bancarias, correo productivo ni credenciales de SEDERG_PAY en Ballerine.
- No expongas los puertos del ejemplo a Internet.
- La aprobación del backoffice de Ballerine es una señal del proveedor, no una aprobación regulatoria ni una autorización para ejecutar remesas.
- La licencia del monorepo es mixta: cada componente se rige por la licencia local más cercana y, si no hay otra, por Elastic License 2.0. La revisión legal debe preceder a cualquier uso comercial o distribución.

## Conectar la PoC local con SEDERG_PAY

La integración ya está implementada pero permanece desactivada hasta configurar
las variables `SEDERG_BALLERINE_*` y establecer `SEDERG_BALLERINE_ENABLED=1`. Para
una prueba local con el workflow service en el host y el backend en Docker:

```dotenv
SEDERG_BALLERINE_ENABLED=1
SEDERG_BALLERINE_BASE_URL=http://host.docker.internal:3000
SEDERG_BALLERINE_API_KEY=<api key del servicio Ballerine>
SEDERG_BALLERINE_WORKFLOW_ID=<id del workflow KYC>
SEDERG_BALLERINE_COLLECTION_URL=http://localhost:5173
SEDERG_BALLERINE_WEBHOOK_SECRET=<mismo valor que WEBHOOK_SECRET en Ballerine>
SEDERG_BALLERINE_RETURN_URL=http://localhost:5173
```

El contenedor del backend debe poder resolver `host.docker.internal`. El puerto
5173 del ejemplo Ballerine choca con el frontend de SEDERG_PAY; detén uno de los
dos o publica el flujo de colección en otro puerto y ajusta
`SEDERG_BALLERINE_COLLECTION_URL` o `SEDERG_BALLERINE_COLLECTION_URL_TEMPLATE`.

Configura en el workflow de Ballerine la suscripción webhook hacia:

```text
http://host.docker.internal:8080/api/v1/kyc/webhooks/ballerine
```

Aplica la migración antes de iniciar sesiones:

```powershell
docker compose run --rm --no-deps backend php yii migrate --migrationPath=@app/migrations
```

Los endpoints añadidos son `POST /api/v1/kyc/session` y
`POST /api/v1/kyc/webhooks/ballerine`. Consulta
[INTEGRATION_CONTRACT.md](INTEGRATION_CONTRACT.md).

## Límites de esta fase

La PoC verifica que el flujo de captura y revisión manual de Ballerine puede
arrancar localmente y que el adaptador puede crear sesiones y recibir eventos.
Sigue sin estar permitido procesar documentos o identidades reales. La conexión
productiva requiere validar compatibilidad, seguridad, licencias, residencia de
datos y tratamiento de datos en Rusia y Cuba.

## Fuente upstream

https://github.com/ballerine-io/ballerine
