# REAXML Importer Plugin for WordPress

A WordPress plugin designed to automate the import of REAXML files into the Easy Property Listings system.

## 🧩 Features

- Background Scheduler
 
  Initiates a background timer using WordPress's cron system to schedule recurring tasks.

- RESTful Upload Endpoint
 
  Provides a RESTful API endpoint (/wp-json/reaxml-importer/v1/upload) to receive and process REAXML files.

- Database Listing Loader
 
  Periodically loads listing resources from the database for processing.

- Batch Import to Properties

  Processes and imports all listing resources into the properties post type in batches.

## 🔧 Installation

- Upload Plugin
 
  Upload the plugin files to the /wp-content/plugins/reaxml-importer directory, or install the plugin through the WordPress plugins screen directly.

- Activate Plugin
 
  Activate the plugin through the 'Plugins' screen in WordPress.

- Configure Settings
 
  Navigate to Settings > REAXML Importer to configure plugin options.

## 🚀 Usage

- Uploading REAXML Files
 
  Send a POST request to /wp-json/reaxml-importer/v1/upload with the REAXML file attached.

- Scheduled Imports
  The plugin uses WordPress's cron system to schedule imports. Ensure your WordPress cron is set up correctly.

## 🗃️ Dependencies

- WordPress 5.0 or higher
- PHP 7.2 or higher
- Easy Property Listings plugin

## 📄 License

  This plugin is licensed under the MIT License. See the LICENSE file for details.