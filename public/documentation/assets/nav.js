/* ==========================================================================
   POS Documentation — navigation model (single source of truth)
   Edit this file to add/rename/reorder pages. docs.js renders the sidebar,
   breadcrumbs, search index and prev/next pager from it automatically.

   Each page: { id, label, href, icon, keywords }
   - id       : matches <body data-nav="..."> on the page
   - icon     : key into the ICONS map in docs.js
   - keywords : extra search terms (space separated)
   ========================================================================== */
/* Site branding — change the name here, and replace the logo file at
   public/assets/documentation/images/logo.svg with your own to rebrand
   the whole guide (header + footer). */
window.DOCS_SITE = {
  name: "POS",
  // logo: "/assets/documentation/images/logo.svg",  // optional override
};

window.DOCS_NAV = [
  {
    title: "Getting started",
    items: [
      { id: "home",            label: "Welcome",            href: "/documentation/index.html",            icon: "home",      keywords: "introduction start overview" },
      { id: "installation",    label: "Installation & setup", href: "/documentation/installation.html",    icon: "wrench",    keywords: "install requirements prerequisites server php mysql upload unzip wizard installer license activate htaccess shared hosting cpanel" },
      { id: "cron",            label: "Cron job setup",     href: "/documentation/cron.html",              icon: "clock",     keywords: "cron cronjob crontab scheduler schedule run background task automatic every minute cpanel plesk directadmin shared hosting artisan php path scheduled tasks server" },
      { id: "getting-started", label: "Getting started",    href: "/documentation/getting-started.html",   icon: "rocket",    keywords: "setup first time login company first day checklist" },
      { id: "dashboard",       label: "Dashboard tour",     href: "/documentation/dashboard.html",         icon: "dashboard", keywords: "home stats kpi overview metrics setup checklist get your store ready progress onboarding dismiss" },
      { id: "navigation",      label: "Finding your way",   href: "/documentation/navigation.html",        icon: "compass",   keywords: "menu sidebar search command palette dark mode language topbar" },
    ],
  },
  {
    title: "Selling (Point of Sale)",
    items: [
      { id: "cashier",          label: "The POS screen",        href: "/documentation/cashier.html",          icon: "pos",      keywords: "checkout cashier sell ring up cart barcode scan credit account full credit on account oversell negative stock out of stock shelf location where is this item cash in cash out drawer" },
      { id: "discounts",        label: "Discounts & pricing",   href: "/documentation/discounts.html",        icon: "tag",      keywords: "discount price override percent coupon" },
      { id: "payments",         label: "Taking payments",       href: "/documentation/payments.html",         icon: "card",     keywords: "cash card split tender qr stripe razorpay paystack online store credit customer overpayment custom offline record only mobile money required reference" },
      { id: "weight-items",     label: "Weighed & scale items", href: "/documentation/weight-items.html",     icon: "scale",    keywords: "weight scale plu barcode supermarket produce" },
      { id: "returns",          label: "Returns & refunds",     href: "/documentation/returns.html",          icon: "refund",   keywords: "refund return exchange money back reason" },
      { id: "customer-display", label: "Customer display",      href: "/documentation/customer-display.html", icon: "pos",      keywords: "customer display cfd second screen facing shopper monitor pole mirror cart welcome thank you offers upi qr scan pay pay from display" },
      { id: "kiosk",            label: "Self-ordering kiosk",   href: "/documentation/kiosk.html",            icon: "store",    keywords: "kiosk self service self ordering order station touch screen tablet unattended pickup number pickup code queue pay at counter self checkout attract idle supervisor exit upi claim launch button start full order detail" },
      { id: "digital-receipts", label: "Digital receipts",      href: "/documentation/digital-receipts.html", icon: "receipt",  keywords: "digital receipt paperless qr scan phone public link share whatsapp email no paper" },
      { id: "whatsapp",         label: "WhatsApp sharing",       href: "/documentation/whatsapp.html",         icon: "receipt",  keywords: "whatsapp click to chat meta cloud api connection template delivery log status retry receipt quotation credit note statement customer supplier payment voucher" },
      { id: "offline",          label: "Selling offline",       href: "/documentation/offline.html",          icon: "offline",  keywords: "no internet offline sync queue pwa install failed sync retry resync replay dismiss stuck sync log did not sync" },
    ],
  },
  {
    title: "Sales & customers",
    items: [
      { id: "sales-history",     label: "Sales history",       href: "/documentation/sales-history.html",     icon: "receipt",  keywords: "sales list invoices receipts view void new sale back office create sale phone order manual invoice raise" },
      { id: "quotations",        label: "Quotations",          href: "/documentation/quotations.html",        icon: "note",     keywords: "quotation quote estimate proposal prospect offer share link accepted declined expired cancelled superseded revision convert sale pdf print whatsapp" },
      { id: "customers",         label: "Customers",           href: "/documentation/customers.html",         icon: "customers",keywords: "customer contacts groups credit loyalty tabs detail overview addresses purchases payments returns statement ledger running balance group discount inherit follow the group import csv excel preview mapping conflict" },
      { id: "loyalty",           label: "Loyalty points",      href: "/documentation/loyalty.html",           icon: "star",     keywords: "loyalty points reward rewards programme program earn redeem redemption expiry expire multiplier tier club liability balance customer retention" },
      { id: "customer-payments", label: "Customer payments",   href: "/documentation/customer-payments.html", icon: "cash",     keywords: "credit receivables outstanding balance collect pay overpayment surplus store credit customer advance" },
      { id: "shifts",            label: "Shifts & cash drawer", href: "/documentation/shifts.html",           icon: "clock",    keywords: "shift open close x report z report cash drawer float variance pay supplier from till pay out payout cash in cash out petty cash top up bank deposit safe no sale drawer open from the cashier screen" },
    ],
  },
  {
    title: "Online sales",
    items: [
      { id: "sales-channels", label: "Shopify & WooCommerce", href: "/documentation/sales-channels.html", icon: "refresh", keywords: "shopify woocommerce commerce online store sales channel integration connection api key client id secret webhook sync products variants categories brands images customers inventory locations mappings safety stock" },
      { id: "channel-orders", label: "Channel orders",         href: "/documentation/channel-orders.html", icon: "cart",    keywords: "online order paid reserve reservation allocation split store fulfill fulfilment cancellation refund retry provider sync shopify woocommerce" },
    ],
  },
  {
    title: "Products & catalog",
    items: [
      { id: "products",        label: "Products",              href: "/documentation/products.html",        icon: "box",     keywords: "product item sku barcode price catalog import export bulk images photos upload image url fetch remote https kit bundle components recalculate auto price automatic sku generate code numbering in-store barcode ean-13 loose bulk own-made shelf location aisle rack bin bulk set location bulk delete remove selected products archive inactive" },
      { id: "categories",      label: "Categories & brands",   href: "/documentation/categories.html",      icon: "tree",    keywords: "category brand organise taxonomy import export csv excel bulk parent logo upload url fetch remote https" },
      { id: "units",           label: "Units of measure",      href: "/documentation/units.html",           icon: "grip",    keywords: "unit measurement kg litre piece conversion" },
      { id: "labels",          label: "Barcode labels",        href: "/documentation/labels.html",          icon: "barcode", keywords: "label print barcode sticker a4 sheet shelf location aisle rack on label" },
    ],
  },
  {
    title: "Inventory",
    items: [
      { id: "inventory",        label: "Stock overview",        href: "/documentation/inventory.html",        icon: "archive",  keywords: "stock levels available low stock movements activity location shelf aisle rack column batch breakdown cost selling price mrp export csv excel" },
      { id: "shelf-locations",  label: "Shelf locations",       href: "/documentation/shelf-locations.html",  icon: "compass",  keywords: "shelf location locations aisle rack bin shelf position where is it put away putaway pick face main spot back stock godown store room fridge refrigerated cold chain locked cabinet controlled bulk set clear import export count sheet walk order blind count find product" },
      { id: "batches",          label: "Batches & expiry",      href: "/documentation/batches.html",          icon: "calendar", keywords: "batch expiry lot pharmacy fefo expired delete remove automatic batch selection auto pick soonest expiry oldest stock change batch picker shelf location batch wise pricing cost selling price mrp product based" },
      { id: "adjustments",      label: "Stock adjustments",     href: "/documentation/adjustments.html",      icon: "edit",     keywords: "adjust write off damage correction reason scan barcode camera f2 import csv excel preview draft sku variant" },
      { id: "stock-takes",      label: "Stock reconciliation",  href: "/documentation/stock-takes.html",      icon: "checkall", keywords: "stock take cycle count physical count variance scan barcode camera f2 print count sheet printable walk order blind count hide expected paper import csv excel preview draft sku variant" },
      { id: "transfers",        label: "Stock transfers",       href: "/documentation/transfers.html",        icon: "transfer", keywords: "transfer move between stores branches scan barcode camera f2 pick from put away location shelf" },
    ],
  },
  {
    title: "Purchasing & suppliers",
    items: [
      { id: "suppliers",         label: "Suppliers",            href: "/documentation/suppliers.html",         icon: "truck",  keywords: "supplier vendor contact tabs detail overview purchases payments returns statement ledger running balance payable" },
      { id: "purchases",         label: "Purchases",            href: "/documentation/purchases.html",         icon: "cart",   keywords: "purchase order receive goods stock in cost wac markup scan barcode selling price mrp margin" },
      { id: "purchase-returns",  label: "Purchase returns",     href: "/documentation/purchase-returns.html",  icon: "refund", keywords: "return to supplier debit note" },
      { id: "supplier-payments", label: "Supplier payments",    href: "/documentation/supplier-payments.html", icon: "card",   keywords: "pay supplier bill payable balance" },
      { id: "expenses",          label: "Expenses",             href: "/documentation/expenses.html",          icon: "cash",   keywords: "expense rent utilities bills running cost cash out category paid drawer petty cash till cash expense reduces drawer expected cash" },
    ],
  },
  {
    title: "Reports & tax",
    items: [
      { id: "reports",           label: "Reports",            href: "/documentation/reports.html",           icon: "pie",     keywords: "report sales summary by product aged receivables export loyalty points liability profit margin cost of goods cogs rebuild historical sale costs backfill 100% margin" },
      { id: "scheduled-reports", label: "Scheduled reports",  href: "/documentation/scheduled-reports.html", icon: "clock",   keywords: "scheduled report email automatic daily weekly monthly recurring deliver recipients cron" },
      { id: "taxes",             label: "Taxes",              href: "/documentation/taxes.html",             icon: "percent", keywords: "tax vat gst components groups classification" },
      { id: "drug-schedules",label: "Drug schedules",     href: "/documentation/drug-schedules.html",icon: "note",  keywords: "pharmacy schedule controlled drug compliance" },
    ],
  },
  {
    title: "Accounting",
    items: [
      { id: "accounting",    label: "Accounting",         href: "/documentation/accounting.html",    icon: "book",  keywords: "accounting chart of accounts business mappings fiscal periods opening balances journal day book ledger trial balance profit loss balance sheet cash flow debit credit equity assets liabilities bookkeeping" },
    ],
  },
  {
    title: "Settings & administration",
    items: [
      { id: "settings",      label: "Settings",           href: "/documentation/settings.html",      icon: "settings", keywords: "company branding theme regional localization currency formatting receipt cashier scale pricing batch wise product based numbering sku format barcode prefix loyalty scheduler cron smtp email payment methods custom offline mobile money required reference gateways whatsapp backup updates license privacy terms negative stock oversell allow stock locations shelf fields rename aisle rack bin rebuild historical sale costs system health security single session device header footer custom scripts analytics tag manager tracking" },
      { id: "users-roles",   label: "Users & roles",      href: "/documentation/users-roles.html",   icon: "shield",   keywords: "user staff role permission access security login deactivate signed out immediately force logout sessions revoke devices email change confirmation pending store access restrict which stores last super admin privilege escalation" },
      { id: "stores",        label: "Stores & terminals", href: "/documentation/stores.html",        icon: "store",    keywords: "store location terminal register till" },
      { id: "hardware",      label: "Hardware & printing",href: "/documentation/hardware.html",      icon: "printer",  keywords: "printer receipt thermal cash drawer scanner barcode esc pos bridge diagnostics" },
      { id: "languages",     label: "Languages",          href: "/documentation/languages.html",     icon: "globe",    keywords: "language translation locale rtl export import" },
      { id: "backup",        label: "Backup & restore",   href: "/documentation/backup.html",        icon: "database", keywords: "backup restore download safety data" },
      { id: "updates",       label: "Updates & license",  href: "/documentation/updates.html",       icon: "refresh",  keywords: "update upgrade version license activation" },
    ],
  },
  {
    title: "Help",
    items: [
      { id: "faq",             label: "FAQ",              href: "/documentation/faq.html",             icon: "help",     keywords: "questions frequently asked answers" },
      { id: "troubleshooting", label: "Troubleshooting",  href: "/documentation/troubleshooting.html", icon: "wrench",   keywords: "problem fix error not working broken help" },
      { id: "glossary",        label: "Glossary",         href: "/documentation/glossary.html",        icon: "book",     keywords: "terms definitions meaning words" },
    ],
  },
];
