#!/bin/bash

set -e

echo "🚀 Starting build process..."

# Create build directory
mkdir -p build

# Step 1: Install/update dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Step 2: Create .phar with Box
echo "📦 Creating .phar file with Box..."
box compile

echo "✅ File created: dkvol.phar"

# Step 3: Verify PHPacker is available
if command -v phpacker >/dev/null 2>&1; then
    echo "🔧 Generating standalone executables..."
    echo "  → Building for all platforms..."
    phpacker build all --src=dkvol.phar --dest=build/ || echo "⚠️  All platforms build failed"
    echo "✅ Building standalone executables completed!"
else
    echo "⚠️  PHPacker not available, skipping standalone executables"
    echo "💡 You can still use the .phar file with: php dkvol.phar"
fi

echo "✅ Build completed!"
echo "📁 List of generated files:"
ls -la dkvol.phar build/ 2>/dev/null || ls -la dkvol.phar