# SplitMate 💰

A modern expense splitting application built with Laravel and Tailwind CSS. SplitMate helps groups of people track shared expenses, calculate who owes what, and manage settlements efficiently.

## ✨ Features

- **Expense Tracking**: Add and manage shared expenses with descriptions, amounts, and receipt photos
- **User Management**: Add/remove users and manage active status
- **Automatic Calculations**: Automatically calculate how much each person owes based on expense distribution
- **Settlement Tracking**: Track payments between users to settle debts
- **Payback System**: Handle individual paybacks between users
- **Modern UI**: Clean, responsive interface built with Tailwind CSS
- **Real-time Updates**: Live updates using Laravel's built-in features

## 🛠️ Technology Stack

- **Backend**: Laravel 12.x
- **Frontend**: Blade templates with Tailwind CSS 4.x
- **Database**: SQLite (default) / MySQL / PostgreSQL
- **Build Tool**: Vite
- **PHP Version**: 8.2+

## 📋 Prerequisites

Before you begin, ensure you have the following installed on your system:

- **PHP 8.2 or higher**
- **Composer** (PHP dependency manager)
- **Node.js 18+ and npm** (for frontend assets)
- **Git** (for version control)

### Optional but Recommended:
- **Laravel Sail** (Docker-based development environment)
- **MySQL/PostgreSQL** (for production databases)

## 🚀 Installation

### Method 1: Using Composer (Recommended)

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/splitmate.git
   cd splitmate
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**
   ```bash
   npm install
   ```

4. **Environment Setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Database Setup**
   ```bash
   # For SQLite (default)
   touch database/database.sqlite
   
   # Or configure MySQL/PostgreSQL in .env file
   # DB_CONNECTION=mysql
   # DB_HOST=127.0.0.1
   # DB_PORT=3306
   # DB_DATABASE=splitmate
   # DB_USERNAME=root
   # DB_PASSWORD=
   ```

6. **Run Migrations**
   ```bash
   php artisan migrate
   ```

7. **Seed Database (Optional)**
   ```bash
   php artisan db:seed
   ```

8. **Build Frontend Assets**
   ```bash
   npm run build
   ```

9. **Start the Development Server**
   ```bash
   php artisan serve
   ```

   The application will be available at `http://localhost:8000`

### Method 2: Using Laravel Sail (Docker)

1. **Clone and setup**
   ```bash
   git clone https://github.com/yourusername/splitmate.git
   cd splitmate
   composer install
   ```

2. **Start Sail**
   ```bash
   ./vendor/bin/sail up -d
   ```

3. **Run migrations**
   ```bash
   ./vendor/bin/sail artisan migrate
   ```

4. **Build assets**
   ```bash
   ./vendor/bin/sail npm run build
   ```

   The application will be available at `http://localhost`

## 🔧 Development

### Running in Development Mode

For development with hot reloading:

```bash
# Terminal 1: Start Laravel server
php artisan serve

# Terminal 2: Start Vite dev server
npm run dev

# Or use the combined command
composer run dev
```

### Database Management

```bash
# Create a new migration
php artisan make:migration create_table_name

# Run migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Reset all migrations
php artisan migrate:reset
```

### Frontend Development

```bash
# Watch for changes and rebuild
npm run dev

# Build for production
npm run build
```

## 📁 Project Structure

```
splitmate/
├── app/
│   ├── Http/Controllers/     # Application controllers
│   └── Models/              # Eloquent models
├── database/
│   ├── migrations/          # Database migrations
│   └── seeders/            # Database seeders
├── resources/
│   ├── css/                # CSS files
│   ├── js/                 # JavaScript files
│   └── views/              # Blade templates
├── routes/
│   └── web.php             # Web routes
└── public/                 # Public assets
```

## 🎯 Usage

### Adding Users
1. Navigate to Settings
2. Add new users with names
3. Manage user active status

### Creating Expenses
1. Go to the main dashboard
2. Click "Add Expense"
3. Fill in expense details:
   - Description
   - Amount
   - Who paid
   - Receipt photo (optional)
   - Date

### Managing Settlements
1. View calculated balances
2. Create settlements for payments between users
3. Track payment confirmations

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter=ExpenseTest

# Run with coverage
php artisan test --coverage
```

## 📦 Production Deployment

1. **Environment Configuration**
   ```bash
   cp .env.example .env
   # Edit .env with production settings
   ```

2. **Install Dependencies**
   ```bash
   composer install --optimize-autoloader --no-dev
   npm ci && npm run build
   ```

3. **Database Setup**
   ```bash
   php artisan migrate --force
   ```

4. **Cache Configuration**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

## 🌐 Hosting Configuration

### Removing `/public` from URLs

When deploying to shared hosting, you typically need to remove `/public` from your URLs. Here are several methods:

#### Method 1: Move Files (Recommended for Shared Hosting)

1. **Move all files from `public/` to root directory**
   ```bash
   # Move all files from public/ to your domain root
   mv public/* ./
   mv public/.* ./
   rmdir public
   ```

2. **Update `index.php`**
   ```php
   // Change this line in index.php:
   require __DIR__.'/../vendor/autoload.php';
   // To:
   require __DIR__.'/vendor/autoload.php';
   
   // Change this line:
   $app = require_once __DIR__.'/../bootstrap/app.php';
   // To:
   $app = require_once __DIR__.'/bootstrap/app.php';
   ```

#### Method 2: Apache .htaccess (If you have access to document root)

Create a `.htaccess` file in your domain root:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Redirect to public folder
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.*)$ /public/$1 [L,QSA]
</IfModule>
```

#### Method 3: Nginx Configuration

Add this to your Nginx server block:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/your/splitmate/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

#### Method 4: cPanel/Shared Hosting

1. **Upload your Laravel files** to a subdirectory (e.g., `splitmate/`)
2. **Create a subdomain** pointing to `splitmate/public/`
3. **Or use the File Manager** to move contents of `public/` to `public_html/`

#### Method 5: Using .htaccess Redirect

If you can't modify the document root, create this `.htaccess` in your domain root:

```apache
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ /public/$1 [L]
```

### Environment Variables for Production

Update your `.env` file for production:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_PORT=3306
DB_DATABASE=your-database-name
DB_USERNAME=your-username
DB_PASSWORD=your-password

# Add your production settings here
```

### File Permissions

Set proper permissions for your hosting:

```bash
# Set directory permissions
chmod -R 755 storage bootstrap/cache
chmod -R 644 .env

# Make sure storage is writable
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📝 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## 🆘 Support

If you encounter any issues or have questions:

1. Check the [Issues](https://github.com/yourusername/splitmate/issues) page
2. Create a new issue with detailed information
3. Contact the maintainers

## 🙏 Acknowledgments

- Built with [Laravel](https://laravel.com)
- Styled with [Tailwind CSS](https://tailwindcss.com)
- Icons and UI components from various open-source libraries

---

**Happy Splitting! 💸**