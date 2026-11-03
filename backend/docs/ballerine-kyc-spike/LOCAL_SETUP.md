# Evaluación local aislada de Ballerine

Esta PoC ejecuta el ejemplo oficial de KYC manual-review fuera de los contenedores y del ciclo de vida de SEDERG_PAY. No se agrega una imagen Docker de Ballerine al compose principal porque el upstream documenta un monorepo Node/pnpm que levanta su propio workflow service, UI SDK y backoffice.

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

## Límites de esta fase

Esta PoC verifica únicamente que el flujo de captura y revisión manual de Ballerine puede arrancar localmente. Todavía no hay integración API, webhook firmado, sincronización de estados, almacenamiento compartido ni acceso a documentos de SEDERG_PAY. La conexión real se implementará después de validar compatibilidad, seguridad y tratamiento de datos en Rusia y Cuba.

## Fuente upstream

https://github.com/ballerine-io/ballerine
