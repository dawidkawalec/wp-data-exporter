#!/bin/bash
# Production build script for WooCommerce Data Exporter & Scheduler
# Version: 1.1.0

set -e

echo "🚀 Building production version..."

# Variables
PLUGIN_SLUG="woo-data-exporter"
VERSION="1.1.0"
BUILD_DIR="build"
DIST_DIR="dist"

# Clean previous builds
echo "🧹 Cleaning previous builds..."
rm -rf $BUILD_DIR
rm -rf $DIST_DIR
mkdir -p $BUILD_DIR
mkdir -p $DIST_DIR

# Copy plugin files
echo "📦 Copying files..."
rsync -av --exclude-from='.buildignore' . $BUILD_DIR/$PLUGIN_SLUG/

# Remove development files from build
echo "🗑️  Removing development files..."
cd $BUILD_DIR/$PLUGIN_SLUG
rm -rf .git .gitignore .cursor examples composer.phar composer.json debug-meta-keys.php build-production.sh .buildignore

# Create ZIP
echo "📦 Creating ZIP archive..."
cd ..
zip -r ../$DIST_DIR/${PLUGIN_SLUG}-v${VERSION}.zip $PLUGIN_SLUG/

echo "✅ Build complete!"
echo "📍 Location: $DIST_DIR/${PLUGIN_SLUG}-v${VERSION}.zip"
echo ""
ls -lh ../$DIST_DIR/${PLUGIN_SLUG}-v${VERSION}.zip

