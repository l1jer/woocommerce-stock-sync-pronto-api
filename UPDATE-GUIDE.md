# Automatic Plugin Update System

This plugin now includes automatic update functionality using GitHub as the source.

## How It Works

The plugin automatically checks GitHub for new releases and notifies WordPress admin when updates are available.

## Setup Instructions

### 1. GitHub Repository Setup

**Update the GitHub URL** in `woocommerce-stock-sync-pronto-avenue-api.php` (line 20):
```php
$wc_sspaa_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/YOUR-USERNAME/woocommerce-stock-sync-pronto-api/',
    __FILE__,
    'woocommerce-stock-sync-pronto-avenue-api'
);
```

Replace `YOUR-USERNAME` with your actual GitHub username.

### 2. Creating a New Release

When you're ready to deploy a new version:

**Step 1: Update version number**
- Update version in `woocommerce-stock-sync-pronto-avenue-api.php` header
- Update version in `README.md` stable tag
- Add changelog entry in `README.md`

**Step 2: Commit and push to GitHub**
```bash
git add .
git commit -m "Release version 1.4.10"
git push origin main
```

**Step 3: Create a GitHub Release**
```bash
# Create a git tag
git tag v1.4.10
git push origin v1.4.10
```

Or via GitHub web interface:
1. Go to your repository on GitHub
2. Click "Releases" → "Create a new release"
3. Tag version: `v1.4.10`
4. Release title: `Version 1.4.10`
5. Description: Copy changelog from README.md
6. Click "Publish release"

### 3. How WordPress Sites Detect Updates

- WordPress checks GitHub every 12 hours
- Manual check: Go to Dashboard → Updates
- Update notification appears when new tagged release is available
- Click "Update Now" to install

### 4. For Private Repositories

If your GitHub repository is private, uncomment and set the authentication token in the plugin:

```php
$wc_sspaa_update_checker->setAuthentication('your-github-token-here');
```

**To create a GitHub token:**
1. GitHub Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Generate new token
3. Select scope: `repo` (Full control of private repositories)
4. Copy token and add it to the plugin code

### 5. Update Frequency

The plugin checks for updates:
- Every 12 hours automatically
- When you visit Dashboard → Updates
- When you visit Plugins page

## Version Naming Convention

Use semantic versioning with git tags:
- `v1.4.9` - Major release
- `v1.4.10` - Minor patch/hotfix
- `v1.5.0` - Feature release

## Testing Updates

Before releasing to production:

1. **Test on staging site first**
2. Create a pre-release tag: `v1.4.10-beta`
3. Set branch to test beta: `$wc_sspaa_update_checker->setBranch('beta');`
4. Test the update process
5. Once verified, create the stable release

## Troubleshooting

**Updates not showing:**
- Check GitHub repository URL is correct
- Verify git tag format starts with `v` (e.g., `v1.4.10`)
- Check WordPress can access GitHub (firewall/network issues)
- Clear WordPress transients: go to Plugins and click "Check Again"

**Update fails:**
- Verify plugin slug matches in all places
- Check file permissions on plugin directory
- Review error logs in wp-content/debug.log

## Rollback

If an update causes issues:

1. Go to Plugins → WooCommerce Stock Sync
2. Click "View details" 
3. Click "Previous versions" (if available)
4. Or manually upload previous version via FTP

## Files Not Tracked

These files are excluded from updates (in .gitignore):
- `includes/config.php` - API credentials (site-specific)
- `tasks.md` - Development tasks
- `*.log` - Log files
- `.DS_Store` - System files

Make sure to back up `includes/config.php` before updating, though it should be preserved.







