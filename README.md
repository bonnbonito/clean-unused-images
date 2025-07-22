# Clean Unused Images

A WordPress plugin that helps you identify and safely delete unused image files from your uploads directory.

## Description

Clean Unused Images scans your WordPress uploads directory to find files that are:

- Not registered in the WordPress Media Library
- Not referenced in post/page content
- Not thumbnails of existing media files

This helps you reclaim disk space by removing orphaned files that are no longer needed.

## Features

- **Safe Scanning**: Only deletes files that are confirmed to be unused
- **Flexible Filtering**: Search by folder or filename
- **Bulk Operations**: Delete multiple files at once with progress tracking
- **Detailed Logging**: All deletions are logged with timestamps and file sizes
- **Visual Interface**: Clean admin interface with real-time progress updates
- **Permission Checks**: Only administrators can access the tool

## Installation

1. Upload the `clean-unused-images.php` file to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Settings > Clean Unused Images

## Usage

### Basic Scanning

1. Go to **Settings > Clean Unused Images** in your WordPress admin
2. Use the filters to narrow down your search:
   - **Folder**: Enter a specific folder path (e.g., `2025/07`)
   - **Search File Name**: Enter part of a filename to search for
   - **Only show unused files**: Check this to filter out files that are in use

### Bulk Deletion

1. Set your desired filters
2. Click **"Start Scan & Delete"**
3. The tool will:
   - Scan all matching files
   - Check each file against the Media Library and content
   - Delete only confirmed unused files
   - Show real-time progress
   - Log all actions

### Safety Features

- Files in the Media Library are never deleted
- Files referenced in post content are never deleted
- Thumbnail files of existing media are preserved
- All deletions are logged with timestamps
- Confirmation dialog before bulk operations

## File Structure

```
clean-unused-images/
├── clean-unused-images.php    # Main plugin file
└── README.md                  # This file
```

## Technical Details

### Database Queries

The plugin uses optimized database queries to:

- Batch fetch attachment metadata
- Check for featured image usage
- Search post content for file references
- Minimize database load during bulk operations

### File System Operations

- Uses PHP's `RecursiveDirectoryIterator` for efficient file scanning
- Handles both single directory and recursive subdirectory scanning
- Supports various image formats (jpg, jpeg, png, gif, webp)

### Security

- Nonce verification for all AJAX operations
- Capability checks (`manage_options`)
- Input sanitization and validation
- File path validation to prevent directory traversal

## Logging

All deletions are logged to `uploads-browser-deletion-log.txt` in your uploads directory with:

- Timestamp
- Filename
- File size
- Relative path
- Action taken

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Administrator privileges

## Changelog

### Version 1.0.0

- Initial release
- File scanning and identification
- Bulk deletion with progress tracking
- Detailed logging system
- Admin interface with filtering options

## Support

For support or feature requests, please contact the plugin author.

## License

GPL-2.0+

## Author

The Mighty Mo - https://themightymo.com/
