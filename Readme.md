# DockerVol — Portable backup & restore for Docker volumes

A command-line utility for backing up and restoring Docker resources (volumes and images).

## Quick Start

### Download Prebuilt Binaries

Download the latest release from [GitHub Releases](https://github.com/LucianoVandi/docker-backup-cli/releases):

```bash
# Linux x64
wget https://github.com/LucianoVandi/docker-backup-cli/releases/latest/download/dkvol-linux-x64-v1.0.0
chmod +x dkvol-linux-x64-v1.0.0
./dkvol-linux-x64-v1.0.0 --help

# macOS x64
wget https://github.com/LucianoVandi/docker-backup-cli/releases/latest/download/dkvol-macos-x64-v1.0.0
chmod +x dkvol-macos-x64-v1.0.0
./dkvol-macos-x64-v1.0.0 --help

# Windows x64
# Download dkvol-windows-x64-v1.0.0.exe and run directly
```

### Alternative: Using .phar

```bash
# Download .phar file
wget https://github.com/LucianoVandi/docker-backup-cli/releases/latest/download/dkvol-v1.0.0.phar

# Run with PHP
php dkvol-v1.0.0.phar --help
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

# Restore volumes
./dkvol restore:volumes volume1.tar.gz

# Restore with overwrite existing volumes
./dkvol restore:volumes volume1.tar.gz --overwrite

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

# Restore images
./dkvol restore:images nginx_latest.tar.gz

# Restore with overwrite existing images
./dkvol restore:images nginx_latest.tar.gz --overwrite

# List available image backups
./dkvol restore:images --list
```

### Global Options

```bash
# Get help for any command
./dkvol backup:volumes --help
./dkvol restore:images --help

# Show version information
./dkvol --version
```

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
- `build/dkvol-linux-x64` - Linux executable
- `build/dkvol-windows-x64.exe` - Windows executable
- `build/dkvol-macos-x64` - macOS Intel executable
- `build/dkvol-macos-arm64` - macOS Apple Silicon executable

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
