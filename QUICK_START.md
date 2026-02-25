# 🚀 Quick Start Guide - Admin Panel

## তাড়াতাড়ি শুরু করুন (Bangla)

### ১. প্রয়োজনীয় প্যাকেজ ইনস্টল করুন

```bash
cd /home/mokter/smart-it/project-upgrade/new-ecommerce
npm install
```

### ২. Development Server চালু করুন

```bash
npm run dev
```

### ৩. Laravel Route যুক্ত করুন

`routes/web.php` ফাইলে এটি যুক্ত করুন:

```php
require __DIR__.'/admin.php';
```

### ৪. Admin Panel দেখুন

Browser এ যান: `http://localhost:8000/admin`

---

## Quick Start (English)

### 1. Install Dependencies

```bash
cd /home/mokter/smart-it/project-upgrade/new-ecommerce
npm install
```

### 2. Start Dev Server

```bash
npm run dev
```

In another terminal:
```bash
php artisan serve
```

### 3. Add Laravel Route

In `routes/web.php`:
```php
require __DIR__.'/admin.php';
```

### 4. Access Admin Panel

Navigate to: `http://localhost:8000/admin`

---

## 🎨 Pages Available

| Route | Description |
|-------|-------------|
| `/admin/dashboard` | Dashboard with statistics |
| `/admin/orders/all` | All orders |
| `/admin/orders/pending` | Pending orders |
| `/admin/orders/processing` | Processing orders |
| `/admin/orders/delivered` | Delivered orders |
| `/admin/orders/cancelled` | Cancelled orders |

---

## 🔧 Important Files

### Entry Points
- `resources/js/admin/main.jsx` - React entry point
- `resources/js/admin/App.jsx` - Main app component
- `resources/views/admin/index.blade.php` - Laravel blade template

### Configuration
- `tailwind.config.js` - Tailwind custom colors
- `vite.config.js` - Vite build configuration
- `routes/admin.php` - Laravel routes for admin

### Components
- `resources/js/admin/components/common/` - Reusable components
- `resources/js/admin/layouts/` - Layout components
- `resources/js/admin/pages/` - Page components

---

## 📝 To Do After Setup

### 1. Update API Base URL

In `resources/js/admin/services/api.js`:
```javascript
const api = axios.create({
  baseURL: 'YOUR_API_URL/api', // Update this
  // ...
});
```

### 2. Create Backend API Endpoints

Create Laravel API controllers for:
- `/api/admin/dashboard`
- `/api/admin/orders`
- `/api/admin/orders/{id}`
- etc.

### 3. Setup Authentication

Create login page and protect admin routes:
```javascript
// In App.jsx
<Route path="/admin/login" element={<Login />} />
```

---

## 🎯 Features Working Out of the Box

✅ Responsive design
✅ Dark topbar
✅ Collapsible sidebar
✅ Statistics cards
✅ Order table with pagination
✅ Filtering and search
✅ Bulk operations UI
✅ Modal dialogs
✅ Animated transitions

---

## 🐛 Troubleshooting

### "Module not found" error
```bash
npm install
npm run dev
```

### Styles not loading
```bash
npm run build
php artisan serve
```

### Port already in use
```bash
# Change port in vite.config.js
export default defineConfig({
    server: { port: 3001 },
    // ...
});
```

---

## 📚 Documentation

- **Full Documentation**: See `ADMIN_PANEL_README.md`
- **Implementation Details**: See `.admin-panel-implementation-summary.md`
- **Migration Plan**: See `.admin-panel-migration-plan.md`

---

## 💡 Need Help?

1. Check component examples in the code
2. Review React Router documentation
3. Check Tailwind CSS documentation
4. Refer to original customer-web for design
