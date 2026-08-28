#!/usr/bin/env bash
# ==============================================================================
# CraftBrew Bare-Metal LAMP Server Installer & Upgrader
# Works on Debian, Ubuntu, Rocky Linux, AlmaLinux, RHEL, CentOS
# ==============================================================================

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}   🍺 CraftBrew Platform Bare-Metal Setup & Upgrade   ${NC}"
echo -e "${BLUE}                   Version 2.3.0                      ${NC}"
echo -e "${BLUE}======================================================${NC}"

# Check for PHP CLI
if ! command -v php &> /dev/null; then
    echo -e "${RED}[ERROR] PHP is not installed or not in PATH.${NC}"
    echo "Please install PHP 8.0+ (e.g., sudo apt install php php-mysql php-mbstring)"
    exit 1
fi

PHP_VER=$(php -r "echo PHP_VERSION;")
echo -e "${GREEN}[OK]${NC} Found PHP Version: ${PHP_VER}"

# Check PHP extensions
check_ext() {
    local ext=$1
    if php -m | grep -qi "^$ext$"; then
        echo -e "${GREEN}[OK]${NC} PHP Extension '$ext' is enabled."
    else
        echo -e "${YELLOW}[WARN]${NC} PHP Extension '$ext' is missing."
    fi
}

check_ext "pdo"
check_ext "pdo_mysql"
check_ext "mbstring"

# Ensure storage directory exists and set permissions
echo -e "\n${BLUE}[1/3] Setting directory and storage permissions...${NC}"
mkdir -p "$SCRIPT_DIR/assets/docs"
chmod -R 777 "$SCRIPT_DIR/assets/docs" 2>/dev/null || chmod -R 775 "$SCRIPT_DIR/assets/docs" || true

if [ -f "$SCRIPT_DIR/config.php" ]; then
    chmod 666 "$SCRIPT_DIR/config.php" 2>/dev/null || chmod 664 "$SCRIPT_DIR/config.php" || true
fi

echo -e "${GREEN}[OK]${NC} Permissions configured for assets/docs/."

# Handle CLI Upgrade argument
if [[ "$1" == "--upgrade" ]]; then
    echo -e "\n${BLUE}[2/3] Running Database Migrations via CLI...${NC}"
    if [ ! -f "$SCRIPT_DIR/config.php" ]; then
        echo -e "${RED}[ERROR] config.php not found. Run web installer first: http://<your-server-ip>/install.php${NC}"
        exit 1
    fi

    php -r "
        require_once '$SCRIPT_DIR/config.php';
        require_once '$SCRIPT_DIR/db.php';
        try {
            \$logs = run_migrations();
            echo \"\033[0;32m[SUCCESS]\033[0m Applied migrations:\n\";
            foreach (\$logs as \$log) {
                echo \"  - \$log\n\";
            }
            file_put_contents('$SCRIPT_DIR/installed.lock', 'Upgraded via CLI on ' . date('Y-m-d H:i:s') . \"\n\");
        } catch (Throwable \$t) {
            echo \"\033[0;31m[ERROR]\033[0m \" . \$t->getMessage() . \"\n\";
            exit(1);
        }
    "
    echo -e "\n${GREEN}======================================================${NC}"
    echo -e "${GREEN}   🎉 CraftBrew successfully upgraded to latest schema! ${NC}"
    echo -e "${GREEN}======================================================${NC}"
    exit 0
fi

# Handle CLI Permissions-only argument
if [[ "$1" == "--permissions" ]]; then
    echo -e "${GREEN}[SUCCESS] Permissions fixed.${NC}"
    exit 0
fi

echo -e "\n${BLUE}[2/3] Checking Configuration...${NC}"
if [ -f "$SCRIPT_DIR/config.php" ]; then
    echo -e "${GREEN}[OK]${NC} Found existing config.php."
    echo -e "You can run ${YELLOW}./install.sh --upgrade${NC} to apply database migrations directly,"
    echo -e "or open ${BLUE}http://<your-server-ip>/install.php?mode=upgrade${NC} in your browser."
else
    echo -e "${YELLOW}[NOTE]${NC} config.php not found."
    echo -e "Open ${BLUE}http://<your-server-ip>/install.php${NC} in your browser to run the Setup Wizard!"
fi

echo -e "\n${BLUE}[3/3] Ready for Bare-Metal LAMP Server!${NC}"
echo -e "To access CraftBrew, navigate to your web server URL in your browser."
echo -e "${BLUE}======================================================${NC}"
