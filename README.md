# GD Google Places Importer

**By We Are Web Services**

Import business listings from the Google Places API (New) into GeoDirectory, with optional AI-generated descriptions via Claude (Anthropic).

## Features

- Import businesses by region and category from Google Places
- Adjustable search radius (1km–50km)
- Auto-creates GeoDirectory categories if they don't exist
- Duplicate prevention via Google Place ID tracking
- Optional AI-generated descriptions via Claude
- Import history log
- Built-in auto-update via GitHub

## Requirements

- WordPress 5.8+
- GeoDirectory plugin (free or premium)
- Google Cloud account with **Places API (New)** and **Geocoding API** enabled
- Anthropic API account (optional, for AI descriptions)

## Installation

1. Download the latest release zip from the [Releases](https://github.com/tigred24/gd-google-places-importer/releases) page
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the zip and activate

## Setup

### Google Places API
1. Go to [Google Cloud Console](https://console.cloud.google.com)
2. Enable **Places API (New)** and **Geocoding API**
3. Create an API key under **APIs & Services → Credentials**
4. Restrict the key to those two APIs and your server IP

### Claude AI (Optional)
1. Go to [console.anthropic.com](https://console.anthropic.com)
2. Create an account and add billing credits
3. Generate an API key

### Plugin Settings
1. Go to **GD Google Places Importer → Settings**
2. Enter your Google API key and click **Test Connection**
3. Optionally enable Claude AI and enter your Anthropic key
4. Set your default region and post status

## Running an Import

1. Go to **GD Google Places Importer → Import**
2. Set your region, business type, search radius, and limit
3. Click **Start Import**
4. Review imported drafts under **GeoDirectory → Places**

## Changelog

### 1.0.0
- Initial release
- Google Places API (New) integration
- Optional Claude AI descriptions
- Duplicate prevention
- Import history log
- Built-in GitHub auto-updater
- Adjustable search radius

## License

GPL2 — See [LICENSE](LICENSE) file.
