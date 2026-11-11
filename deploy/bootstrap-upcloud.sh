#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — bootstrap de un Cloud Server UpCloud (Ubuntu 24.04)
#
# Ejecutar como root UNA vez:
#   scp deploy/bootstrap-upcloud.sh root@IP:/root/
#   ssh root@IP 'bash /root/bootstrap-upcloud.sh'
#
# Idempotente: se puede volver a ejecutar sin romper nada.
# No contiene secretos de aplicación; esos van en ~/sederg-pay/.env
# =============================================================================
set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DIR="/home/${DEPLOY_USER}/sederg-pay"

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33m[aviso] %s\033[0m\n' "$*"; }

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ejecuta este script como root (sudo bash bootstrap-upcloud.sh)." >&2
  exit 1
fi

log "Actualizando el sistema"
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
apt-get update -y
apt-get upgrade -y

log "Instalando utilidades base"
apt-get install -y ca-certificates curl gnupg git ufw fail2ban unattended-upgrades

log "Creando usuario ${DEPLOY_USER}"
if ! id "${DEPLOY_USER}" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" "${DEPLOY_USER}"
  usermod -aG sudo "${DEPLOY_USER}"
  echo "${DEPLOY_USER} ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${DEPLOY_USER}"
  chmod 440 "/etc/sudoers.d/90-${DEPLOY_USER}"
else
  echo "El usuario ${DEPLOY_USER} ya existe."
fi

# Reutiliza la clave SSH con la que entraste como root.
if [[ -s /root/.ssh/authorized_keys ]]; then
  install -d -m 700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh"
  install -m 600 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" \
    /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
  echo "Clave SSH copiada a ${DEPLOY_USER}."
else
  warn "No hay /root/.ssh/authorized_keys. Añade tu clave pública a /home/${DEPLOY_USER}/.ssh/authorized_keys antes de cerrar la sesión de root."
fi

log "Instalando Docker Engine + Compose plugin (repositorio oficial)"
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  arch="$(dpkg --print-architecture)"
  codename="$(. /etc/os-release && echo "${VERSION_CODENAME}")"
  printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu %s stable\n' \
    "${arch}" "${codename}" > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
  echo "Docker ya está instalado: $(docker --version)"
fi

usermod -aG docker "${DEPLOY_USER}"
systemctl enable --now docker
systemctl enable --now fail2ban

log "Configurando ufw (segunda barrera; el firewall real es el de UpCloud)"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'ACME + redirect'
ufw allow 443/tcp comment 'HTTPS'
ufw --force enable

log "Habilitando parches de seguridad automáticos"
dpkg-reconfigure -f noninteractive unattended-upgrades || true

log "Añadiendo swap si hay poca RAM"
mem_mb="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
if [[ "${mem_mb}" -lt 3500 && ! -f /swapfile ]]; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo "Swap de 2G activada (RAM detectada: ${mem_mb} MB)."
else
  echo "Swap no necesaria o ya existente (RAM: ${mem_mb} MB)."
fi

log "Preparando ${APP_DIR}"
install -d -m 755 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "${APP_DIR}"

log "Comprobando versiones"
docker --version
docker compose version
php --version 2>/dev/null || echo "PHP no instalado en el host (se usa dentro del contenedor; correcto)."

cat <<EOF

=============================================================================
Bootstrap completado.

Siguiente:
  1) Sal de root:                      exit
  2) Entra como usuario de despliegue: ssh ${DEPLOY_USER}@IP_PUBLICA
  3) Copia el repositorio en ${APP_DIR}
  4) Sigue deploy/UPCLOUD.md §5 (DNS) y §6 (variables)
=============================================================================
EOF
