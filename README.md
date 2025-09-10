# SplitMate 💰

A sophisticated expense splitting application built with Laravel 12 and Tailwind CSS. SplitMate helps groups of people track shared expenses with **automatic debt reduction**, calculate complex balances, and manage settlements efficiently.

## ✨ Key Features

### 🧮 **Smart Expense Management**
- **Automatic Debt Reduction**: When someone pays for an expense, their share automatically reduces any existing debts they owe to others
- **Equal Splitting**: All expenses are split equally among active users
- **Receipt Tracking**: Upload receipt photos (gallery or camera) for every expense
- **Historical Accuracy**: Maintains correct calculations even when group composition changes

### 👥 **Advanced User Management**
- **Soft Delete System**: Remove users without losing transaction history
- **User Reactivation**: Bring back former members with all their data intact
- **Active/Inactive Status**: Support for 2-10 people with flexible group management
- **User Count Tracking**: Preserves calculation accuracy across group changes

### 💳 **Intelligent Settlement System**
- **Payment Validation**: Prevents overpayment with real-time debt checking
- **Payment Proof**: Screenshot upload for settlement verification
- **Automatic Balance Updates**: Real-time wallet balance calculations
- **Debt Priority System**: Reduces highest debts first for optimal cash flow

### 📊 **Comprehensive Tracking**
- **Wallet Snapshots**: Historical tracking of all wallet states after each transaction
- **Step-by-Step Breakdowns**: Detailed mathematical explanations of all calculations
- **Real-time Balances**: Live updates showing who owes what to whom
- **Transaction History**: Complete audit trail of all expenses and settlements

## 🛠️ Technology Stack

- **Backend**: Laravel 12.x with PHP 8.2+
- **Frontend**: Blade templates with Tailwind CSS 4.x
- **Database**: SQLite (default) / MySQL / PostgreSQL
- **Build Tool**: Vite for asset compilation
- **File Storage**: Laravel Storage with public disk
- **Validation**: Client-side + server-side validation
- **Mobile Support**: Camera integration for receipt capture

## 🚀 Unique Technical Features

### 🧮 **Advanced Debt Calculation Engine**
- **Net Balance Tracking**: Maintains accurate balances between all user pairs
- **Debt Reduction Algorithm**: Automatically reduces existing debts when someone pays
- **Historical Accuracy**: Preserves calculations even when group composition changes
- **Priority-Based Reduction**: Reduces highest debts first for optimal cash flow

### 📸 **Mobile-First Design**
- **Camera Integration**: Direct photo capture for receipts and payments
- **Gallery Selection**: Easy file selection from device gallery
- **Responsive UI**: Optimized for mobile and desktop use
- **Touch-Friendly**: Large buttons and intuitive gestures

### 🔒 **Data Integrity & Validation**
- **Overpayment Prevention**: Real-time validation prevents paying more than owed
- **File Validation**: Image type and size validation for uploads
- **Soft Delete System**: Preserves transaction history when users leave
- **Audit Trail**: Complete transaction history with timestamps

### 📊 **Real-Time Features**
- **Live Calculations**: Per-person amounts update as you type
- **Dynamic Balances**: Wallet status updates immediately
- **Interactive Breakdowns**: Expandable detailed calculations
- **Form Validation**: Instant feedback on form errors

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
│   │   ├── ExpenseController.php    # Main expense & settlement logic
│   │   └── SettingsController.php   # User management
│   └── Models/              # Eloquent models
│       ├── User.php                 # User model with soft delete
│       ├── Expense.php              # Expense model with relationships
│       ├── Settlement.php           # Settlement model
│       └── WalletSnapshot.php       # Historical balance tracking
├── database/
│   ├── migrations/          # Database migrations
│   │   ├── create_expenses_table.php
│   │   ├── create_settlements_table.php
│   │   └── create_wallet_snapshots_table.php
│   └── seeders/            # Database seeders
├── resources/
│   ├── css/                # Tailwind CSS files
│   ├── js/                 # JavaScript for form validation
│   └── views/              # Blade templates
│       ├── layouts/app.blade.php    # Main layout
│       ├── expenses/index.blade.php # Main dashboard
│       └── settings/index.blade.php # User management
├── routes/
│   └── web.php             # Web routes
└── public/                 # Public assets & file storage
```

## 🗄️ Database Schema

### Core Tables:
- **users**: User accounts with soft delete support
- **expenses**: Shared expenses with receipt photos
- **settlements**: Payment records between users
- **wallet_snapshots**: Historical balance states
- **expense_paybacks**: Individual payback tracking

### Key Relationships:
- Users have many expenses (as payer)
- Users have many settlements (as payer/receiver)
- Expenses belong to users (paid_by_user_id)
- Settlements reference two users (from_user_id, to_user_id)
- Wallet snapshots track balance changes over time

## 🌐 API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | Main dashboard with expenses and balances |
| POST | `/expenses` | Create new expense |
| POST | `/settlements` | Record settlement payment |
| GET | `/settings` | User management page |
| POST | `/settings/users` | Update user list |
| DELETE | `/settings/users/{user}` | Soft delete user |
| POST | `/settings/users/{user}/reactivate` | Reactivate user |
| GET | `/wallet-snapshots` | Get historical balance data |

## 🧠 How the Automatic Debt Reduction Works

SplitMate features a sophisticated algorithm that automatically reduces debts when someone pays for an expense:

### Example Scenario:
1. **Alice** owes **Bob** $20
2. **Alice** pays for a $30 dinner (split 3 ways = $10 each)
3. **Alice's** $10 share automatically reduces her debt to Bob from $20 to $10
4. **Bob** now owes **Alice** $10 (the reduction amount)
5. The remaining $20 is split normally among the group

### Key Benefits:
- **No Manual Work**: Debts are reduced automatically
- **Optimal Cash Flow**: Highest debts are reduced first
- **Transparent Calculations**: Every step is explained in detail
- **Historical Accuracy**: All calculations are preserved and auditable

## 🎯 Usage Guide

### 👥 **Managing People**
1. **Add Users**: Go to Settings → Add Person → Enter name
2. **Remove Users**: Click the ghost emoji (👻) to soft-delete
3. **Reactivate**: Former members can be brought back with all their history
4. **Group Size**: Supports 2-10 people with automatic validation

### 💸 **Adding Expenses**
1. **Main Dashboard**: Click "Add New Expense"
2. **Fill Details**:
   - Description (e.g., "Grocery Shopping")
   - Total amount
   - Who paid (dropdown selection)
   - Receipt photo (required - gallery or camera)
   - Date (defaults to today)
3. **Automatic Processing**: The system handles all calculations and debt reductions

### 💳 **Recording Settlements**
1. **View Balances**: Check the wallet status cards
2. **Create Settlement**: Click "Record Settlement"
3. **Select Users**: Who paid → Who received
4. **Enter Amount**: System prevents overpayment
5. **Upload Proof**: Payment screenshot required
6. **Submit**: Balances update automatically

### 📊 **Understanding Your Wallet**
- **Green Balances**: Money others owe you
- **Red Balances**: Money you owe others
- **Net Balance**: Your overall financial position
- **Breakdown Details**: Click to see step-by-step calculations

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

## 🔧 Development Workflow

### Quick Start for Developers
```bash
# Clone and setup
git clone https://github.com/yourusername/splitmate.git
cd splitmate
composer install && npm install

# Database setup
touch database/database.sqlite
php artisan migrate

# Development server
composer run dev  # Runs Laravel + Vite + Queue + Logs
```

### Key Development Commands
```bash
# Database operations
php artisan migrate:fresh --seed  # Reset database with sample data
php artisan tinker                # Interactive PHP shell

# Frontend development
npm run dev                       # Watch mode with hot reload
npm run build                     # Production build

# Testing
php artisan test                  # Run all tests
php artisan test --coverage       # With coverage report
```

### Code Structure Guidelines
- **Controllers**: Handle business logic and data processing
- **Models**: Define relationships and data access patterns
- **Views**: Blade templates with Tailwind CSS styling
- **Migrations**: Database schema changes
- **JavaScript**: Client-side validation and interactions

## 🤝 Contributing

We welcome contributions! Here's how to get started:

1. **Fork the repository**
2. **Create a feature branch**: `git checkout -b feature/amazing-feature`
3. **Follow coding standards**: Use Laravel Pint for PHP formatting
4. **Write tests**: Ensure new features are properly tested
5. **Update documentation**: Keep README and code comments current
6. **Submit a Pull Request**: Include detailed description of changes

### Areas for Contribution
- **UI/UX Improvements**: Better mobile experience, animations
- **Algorithm Enhancements**: More sophisticated debt reduction logic
- **Testing**: Unit and integration tests
- **Documentation**: Code comments, API documentation
- **Performance**: Database optimization, caching strategies

## 📝 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## 🆘 Support & Community

### Getting Help
1. **Documentation**: Check this README and code comments
2. **Issues**: Search existing issues or create a new one
3. **Discussions**: Use GitHub Discussions for questions
4. **Contact**: Reach out to maintainers for urgent issues

### Reporting Bugs
When reporting bugs, please include:
- **Steps to reproduce**: Clear, numbered steps
- **Expected behavior**: What should happen
- **Actual behavior**: What actually happens
- **Environment**: PHP version, Laravel version, browser
- **Screenshots**: If applicable

## 🙏 Acknowledgments

### Core Technologies
- **[Laravel](https://laravel.com)** - The PHP framework that powers the backend
- **[Tailwind CSS](https://tailwindcss.com)** - Utility-first CSS framework
- **[Vite](https://vitejs.dev)** - Fast build tool and dev server

### Inspiration
- **Real-world problems**: Solving actual group expense management challenges
- **User experience**: Mobile-first design for practical usage
- **Mathematical accuracy**: Ensuring fair and transparent calculations

### Special Thanks
- **Laravel Community** - For the amazing ecosystem and documentation
- **Open Source Contributors** - For the tools and libraries that make this possible
- **Beta Testers** - For feedback and bug reports during development

---

## 🎯 Why SplitMate?

SplitMate isn't just another expense splitting app. It's a **sophisticated financial management tool** that handles the complexities of group finances with:

- **Zero Manual Work**: Automatic debt reduction eliminates tedious calculations
- **Complete Transparency**: Every calculation is explained step-by-step
- **Mobile-First**: Designed for real-world usage with camera integration
- **Historical Accuracy**: Maintains data integrity across group changes
- **Professional Grade**: Built with enterprise-level Laravel architecture

**Ready to revolutionize your group expense management? Let's get splitting! 💸**