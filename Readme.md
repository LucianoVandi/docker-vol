# DockerVol — Portable backup & restore for Docker volumes

[![codecov](https://codecov.io/gh/LucianoVandi/docker-backup-cli/branch/main/graph/badge.svg)](https://codecov.io/gh/LucianoVandi/docker-backup-cli)

A command-line utility for backing up and restoring Docker resources (volumes and images).

## Quick Start

### Download Prebuilt Binaries

Download the latest release from [GitHub Releases](https://github.com/LucianoVandi/docker-backup-cli/releases):
Set `VERSION` to the release tag you want to install, for example `v1.2.3`.

```bash
VERSION=vX.Y.Z

# Linux x64
wget https://github.com/LucianoVandi/docker-backup-cli/releases/download/${VERSION}/dkvol-linux-x64-${VERSION}
chmod +x dkvol-linux-x64-${VERSION}
./dkvol-linux-x64-${VERSION} --help

# macOS x64
wget https://github.com/LucianoVandi/docker-backup-cli/releases/download/${VERSION}/dkvol-macos-x64-${VERSION}
chmod +x dkvol-macos-x64-${VERSION}
./dkvol-macos-x64-${VERSION} --help

# Windows x64
# Download dkvol-windows-x64-${VERSION}.exe from the release and run directly
```

### Alternative: Using .phar

```bash
VERSION=vX.Y.Z

# Download .phar file
wget https://github.com/LucianoVandi/docker-backup-cli/releases/download/${VERSION}/dkvol-${VERSION}.phar

# Run with PHP
php dkvol-${VERSION}.phar --help
```

## Requirements

### Docker
- **Minimum supported version**: Docker 23.0+

### System Requirements
- **For standalone executables**: Docker installed and running
- **For .phar file**: PHP 8.2+ + Docker CLI access
- Access to Docker socket (`/var/run/docker.sock`)

## Usage

### Volume Operations

```bash
# List available volumes
./dkvol backup:volumes --list

# Backup specific volumes
./dkvol backup:volumes volume1 volume2

# Backup with custom output directory
./dkvol backup:volumes volume1 --output-dir ./my-backups

# Create uncompressed backups
./dkvol backup:volumes volume1 --no-compression

# Override Docker command timeout
./dkvol backup:volumes volume1 --timeout=600

# Restore volumes
./dkvol restore:volumes volume1.tar.gz

# Restore with overwrite existing volumes
./dkvol restore:volumes volume1.tar.gz --overwrite

# Run full tar integrity validation before restore
./dkvol restore:volumes volume1.tar.gz --deep-validate

# List available volume backups
./dkvol restore:volumes --list
```

### Image Operations

```bash
# List available images
./dkvol backup:images --list

# Backup specific images
./dkvol backup:images nginx:latest mysql:8.0

# Backup image by ID
./dkvol backup:images sha256:1234567890abcdef

# Backup with custom output directory
./dkvol backup:images nginx:latest --output-dir ./my-backups

# Create uncompressed backups
./dkvol backup:images nginx:latest --no-compression

# Override Docker command timeout
./dkvol backup:images nginx:latest --timeout=600

# Restore images
./dkvol restore:images nginx%3Alatest.tar.gz

# Restore with overwrite existing images
./dkvol restore:images nginx%3Alatest.tar.gz --overwrite

# Run full tar integrity validation before restore
./dkvol restore:images nginx%3Alatest.tar.gz --deep-validate

# List available image backups
./dkvol restore:images --list
```

Image backup filenames are URL-encoded so Docker references remain reversible. For example, `nginx:latest`
is stored as `nginx%3Alatest.tar.gz`. Older underscore filenames such as `nginx_latest.tar.gz` are still
decoded on a best-effort basis for compatibility.

### Common Options

```bash
# Get help for any command
./dkvol backup:volumes --help
./dkvol restore:images --help

# Disable compression for backup commands
./dkvol backup:volumes volume1 --no-compression
./dkvol backup:images nginx:latest --no-compression

# Override Docker command timeout in seconds
./dkvol backup:volumes volume1 --timeout=600
./dkvol restore:images nginx%3Alatest.tar.gz --timeout=600

# Show version information
./dkvol --version
```

`--no-compression` creates `.tar` archives instead of the default `.tar.gz` archives. `--timeout` takes
precedence over `BACKUP_TIMEOUT`; if neither is set, Docker commands use a 300 second timeout.

## Development Setup

### Prerequisites

- Docker (for development environment)
- Make (optional, for convenience commands)

### Getting Started

```bash
# Clone the repository
git clone https://github.com/LucianoVandi/docker-backup-cli
cd docker-backup-cli

# Build development environment
make build

# Start development container
make dev

# Install dependencies
make install
```

### Development Commands

```bash
make help               # Show all available commands
make build              # Build development container
make dev                # Start development environment
make install            # Install dependencies
make test               # Run test suite
make quality            # Run code quality checks
make build-phar         # Create .phar file
make build-standalone   # Create standalone executables
```

## Architecture

### Project Structure

```
├── bin/console              # CLI entry point
├── src/
│   ├── Command/            # CLI commands
│   ├── Service/            # Business logic services
│   ├── ValueObject/        # Immutable value objects
│   ├── Exception/          # Custom exceptions
│   └── Trait/              # Shared utilities
├── tests/                  # Test suite
├── docker-compose.yml      # Development environment
├── Dockerfile              # Development container
├── box.json               # .phar configuration
└── .github/workflows/     # CI/CD pipeline
```

### Key Components

- **Commands**: CLI interface using Symfony Console
- **Services**: Business logic for backup/restore operations
- **Docker Integration**: Native Docker CLI integration
- **File System**: Efficient handling of large archives
- **Cross-Platform**: Standalone executables for all major platforms

### Supported Archive Formats

- **Compressed**: `.tar.gz` (default, space-efficient)
- **Uncompressed**: `.tar` (faster for large files)
- **Auto-detection**: Handles both formats transparently

## Building from Source

### Development Build

```bash
# Inside development container
composer install
php bin/console --help
```

### Production Build

```bash
# Create .phar file
make build-phar

# Create standalone executables for all platforms
make build-standalone
```

Generated files:
- `dkvol.phar` - Portable PHP archive
- `build/linux/linux-x64` - Linux executable
- `build/linux/linux-arm` - Linux ARM64 executable
- `build/windows/windows-x64.exe` - Windows executable
- `build/mac/mac-x64` - macOS Intel executable
- `build/mac/mac-arm` - macOS Apple Silicon executable

Release assets are renamed with the pushed version tag:
- `dkvol-<version>.phar`
- `dkvol-linux-x64-<version>`
- `dkvol-linux-arm64-<version>`
- `dkvol-macos-x64-<version>`
- `dkvol-macos-arm64-<version>`
- `dkvol-windows-x64-<version>.exe`

### Release Process

Releases are automatically created when you push a version tag:

```bash
# Create and push a version tag
git tag v1.0.0
git push origin v1.0.0

# GitHub Actions will automatically:
# 1. Build all executables
# 2. Create a GitHub release
# 3. Upload all assets
```

## Testing

```bash
# Run full test suite
make test

# Run specific test types
vendor/bin/phpunit tests/Unit/
vendor/bin/phpunit tests/Integration/
```

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/new-feature`
3. Make your changes
4. Run tests: `make test`
5. Run quality checks: `make quality`
6. Commit your changes: `git commit -m 'Add new feature'`
7. Push to the branch: `git push origin feat/new-feature`
8. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

- **Issues**: [GitHub Issues](https://github.com/LucianoVandi/docker-backup-cli/issues)
- **Documentation**: This README and command help (`--help`)

## Acknowledgments

- Built with [Symfony Console](https://symfony.com/doc/current/console.html)
- Packaged with [Box](https://github.com/box-project/box)
- Standalone executables with [PHPacker](https://github.com/phpacker/phpacker)
