# BD Site Link

**Version:** 1.7.2  
**Author:** Baltic Design  
**Requires PHP:** 7.4+  
**Requires WordPress:** 5.8+

REST API plugin for AI-based WordPress site management.

## Features

- Site Monitoring: Status, health, error logs
- Update Management: Remote plugin/theme/core updates
- Content Management: Pages and posts with SEO support
- Media Upload: Sideload images from URLs
- Integrations: Gravity Forms, RankMath SEO, Umami Analytics
- Security: Token auth, IP whitelist, rate limiting, CORS

## Installation

1. Upload `bd-site-link` folder to `/wp-content/plugins/`
2. Activate plugin
3. Go to Settings → BD Site Link
4. Paste your API token

## API Endpoints

Base: `/wp-json/bdstatus/v1/`

**Core:** /status, /plugins, /health, /errors
**Updates:** /updates, /refresh-updates, /plugins/{slug}/update, /themes/{slug}/update
**Content:** /pages, /posts (GET, POST, PUT, DELETE)
**Media:** /media/upload-from-url
**Forms:** /forms (?since=YYYY-MM-DD&until=YYYY-MM-DD optional), /forms/{id}/entries
**Analytics:** /analytics

## Example

```bash
curl -X POST https://site.com/wp-json/bdstatus/v1/pages \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title": "New Page", "content": "Content here", "status": "draft"}'
```

## License

GPL v2 or later
