# Admin Panel - React + Tailwind CSS

A modern, responsive admin panel built with React and Tailwind CSS, converted from the customer-web Laravel Blade template.

## 🎯 Features

### ✅ Implemented
- **Dashboard** with statistics cards and recent orders
- **Order Management** 
  - Order list with advanced filtering
  - Bulk operations (Assign, Status Change, Delete)
  - Courier integration (Steadfast, Pathao)
  - Print functionality
  - FC Ratio checking
- **Responsive Design** - Mobile, tablet, and desktop optimized
- **Modern UI Components**
  - Animated stat cards
  - Data tables with pagination
  - Modal dialogs
  - Form inputs
  - Buttons with variants
  - Badges and status indicators
- **Dark Topbar** matching original design
- **Collapsible Sidebar** with nested menus
- **Real-time Notifications** (structure ready)

### 📋 To Be Implemented
- Products Module (CRUD, Categories, Brands, etc.)
- Customers Module
- Users/Employees Module
- Reports & Analytics
- Settings Module
- Socket.IO real-time integration
- Image upload handling
- PDF generation

## 🏗️ Project Structure

```
resources/js/admin/
├── layouts/
│   ├── AdminLayout.jsx      # Main layout wrapper
│   ├── Sidebar.jsx          # Navigation sidebar
│   └── Topbar.jsx           # Top navigation bar
├── pages/
│   ├── dashboard/
│   │   └── Dashboard.jsx    # Dashboard page
│   └── orders/
│       └── OrderList.jsx    # Order management
├── components/
│   └── common/
│       ├── StatCard.jsx     # Statistics card
│       ├── DataTable.jsx    # Reusable table
│       ├── Pagination.jsx   # Table pagination
│       ├── Modal.jsx        # Modal dialog
│       ├── Button.jsx       # Button component
│       ├── Input.jsx        # Form input
│       └── Badge.jsx        # Status badge
├── services/
│   ├── api.js               # Axios instance
│   └── orderService.js      # Order API calls
├── utils/
│   └── helpers.js           # Utility functions
├── App.jsx                  # Main app with routes
└── main.jsx                 # React entry point
```

## 🎨 Design System

### Colors
- **Primary**: `#62B206` (Green)
- **Secondary**: `#2196F3` (Blue)
- **Accent**: `#9828C7` (Purple)
- **Magenta**: `#c500bc`
- **Dark**: `#000000` (Topbar)

### Components
- **Buttons**: Rounded-full (pill shape)
- **Cards**: White background with shadow
- **Tables**: Black header with white text
- **Modals**: Centered with animated overlay

## 🚀 Getting Started

### 1. Install Dependencies

```bash
npm install
```

### 2. Build Assets

For development:
```bash
npm run dev
```

For production:
```bash
npm run build
```

### 3. Setup Laravel Route

Add to your `routes/web.php`:

```php
Route::get('/admin/{any}', function () {
    return view('admin.index');
})->where('any', '.*');
```

### 4. Database Setup

Make sure your Laravel backend has:
- Orders table and API endpoints
- User authentication
- CORS configuration for API calls

## 📱 Routes

- `/admin/dashboard` - Dashboard with statistics
- `/admin/orders/all` - All orders
- `/admin/orders/pending` - Pending orders
- `/admin/orders/processing` - Processing orders
- `/admin/orders/delivered` - Delivered orders
- `/admin/orders/cancelled` - Cancelled orders

## 🔌 API Integration

The admin panel expects the following Laravel API endpoints:

```
GET  /api/admin/dashboard           # Dashboard data
GET  /api/admin/orders              # List orders
GET  /api/admin/orders/{id}         # Get single order
POST /api/admin/orders              # Create order
PUT  /api/admin/orders/{id}         # Update order
DELETE /api/admin/orders/{id}       # Delete order

POST /api/admin/orders/bulk-assign  # Bulk assign
POST /api/admin/orders/bulk-status  # Bulk status change
POST /api/admin/orders/bulk-delete  # Bulk delete
POST /api/admin/orders/courier/steadfast  # Send to Steadfast
POST /api/admin/orders/courier/pathao     # Send to Pathao
```

## 🎯 Next Steps

1. **Implement remaining modules**:
   - Products (with categories, brands, colors, sizes)
   - Customers
   - Users & Roles
   - Settings
   - Reports

2. **Add advanced features**:
   - Socket.IO for real-time notifications
   - Image upload with preview
   - PDF invoice generation
   - Excel export
   - Advanced search & filters

3. **Optimize performance**:
   - Lazy loading for routes
   - Virtual scrolling for large tables
   - Debounced search
   - Caching strategies

4. **Add authentication**:
   - Login page
   - Protected routes
   - Permission-based access

## 🔧 Customization

### Change Colors

Edit `tailwind.config.js`:

```javascript
colors: {
  admin: {
    primary: '#YOUR_COLOR',
    secondary: '#YOUR_COLOR',
    // ...
  }
}
```

### Add New Routes

1. Create page component in `resources/js/admin/pages/`
2. Add route in `App.jsx`
3. Add menu item in `Sidebar.jsx`

## 📖 Component Usage

### StatCard
```jsx
<StatCard
  title="Total Orders"
  value={1250}
  subtitle="Total: ৳125,000"
  icon={FiShoppingCart}
  bgColor="bg-admin-primary"
/>
```

### DataTable
```jsx
<DataTable
  columns={columns}
  data={data}
  loading={loading}
  selectable
  onSelectionChange={setSelected}
  pagination={pagination}
/>
```

### Button
```jsx
<Button 
  variant="primary" 
  size="md" 
  icon={FiPlus}
  loading={isLoading}
>
  Add New
</Button>
```

### Modal
```jsx
<Modal
  isOpen={showModal}
  onClose={() => setShowModal(false)}
  title="Modal Title"
  footer={<Button>Save</Button>}
>
  Modal content
</Modal>
```

## 🐛 Troubleshooting

### Build errors
```bash
# Clear cache and rebuild
rm -rf node_modules
npm install
npm run build
```

### Module not found
```bash
# Make sure all dependencies are installed
npm install react react-dom react-router-dom
npm install clsx framer-motion react-icons
```

### Styles not loading
```bash
# Make sure Tailwind is properly configured
npm run dev
```

## 📄 License

This admin panel is part of the new-ecommerce project.

## 👨‍💻 Developer Notes

- Built with React 19.2
- Tailwind CSS 3.4
- Vite for bundling
- Formik + Yup for forms (ready to use)
- Framer Motion for animations
- React Icons (Feather Icons style)

## 🙏 Credits

- Original design: customer-web Laravel project
- Converted to: React + Tailwind CSS
- Generated by: AI Assistant
