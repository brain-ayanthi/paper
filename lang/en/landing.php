<?php

/*
|--------------------------------------------------------------------------
| Landing page strings
|--------------------------------------------------------------------------
| Public marketing page rendered at "/" when demo mode is ON
| (POS_DEMO_MODE=true). Customer installs never see this page — they go
| straight to login / dashboard. See LandingController + routes/web.php.
|
| Copy is written for non-technical shop owners — plain, friendly language,
| no jargon. Keep it that way when editing.
*/

return [

    'meta' => [
        'title'       => 'Simple point of sale for shops, supermarkets & pharmacies',
        'description' => 'A fast, easy point of sale you own outright — works offline, runs on your own hosting, and never charges a monthly fee.',
    ],

    'nav' => [
        'features'    => 'Features',
        'industries'  => 'Who it\'s for',
        'integrations'=> 'Integrations',
        'businesses'  => 'Businesses',
        'screens'     => 'Screens',
        'pricing'     => 'Pricing',
        'docs'        => 'Documentation',
        'demo'        => 'Live demo',
        'sign_in'     => 'Sign in',
        'dashboard'   => 'Dashboard',
        'buy'         => 'Get Hyper POS',
        'toggle_theme'=> 'Switch light / dark',
        'primary'     => 'Primary navigation',
        'skip_content'=> 'Skip to main content',
    ],

    // Shown on the primary buttons when the visitor is already signed in.
    'go_to_dashboard' => 'Go to your dashboard',

    'totop' => 'Back to top',

    'buy_now' => 'Buy now',

    'demo_badge' => 'Live demo',

    'hero' => [
        'eyebrow'          => 'Yours to own · Works offline · No monthly fees',
        'title'            => 'The point of sale your shop actually owns.',
        'subtitle'         => 'An easy, fast way to sell and run your shop, supermarket, or pharmacy. Pay once, own the software, set it up on your own hosting — and keep selling even when the internet drops.',
        'cta_demo'         => 'Try the live demo',
        'cta_learn'        => 'See what it does',
        'note'             => 'This is a live demo. Sign in with the sample accounts to look around every screen.',
        'pays_label'       => 'Accept payments with',
        'badge_offline'    => 'Offline Orders Sync',
        'badge_payment'    => 'Multi-Payment Support',
        'badge_branch'     => 'Multi-Branch Ready',
        'badge_inventory'  => 'Inventory Management',
        'trust_own'        => 'Pay once, own it forever',
        'trust_source'     => 'Full source code included',
        'trust_nofees'     => 'No monthly fees',
        'trust_offline'    => 'Keeps working offline',
        'trust_selfhosted' => 'Runs on your own hosting',
    ],

    'v2' => [
        'hero' => [
            'eyebrow'       => 'Powerful POS · Online + Offline',
            'title'         => 'Hyper POS connects every sale - at your counter and online.',
            'subtitle'      => 'A modern POS for retail, grocery, supermarkets, pharmacies, and growing multi-store businesses.',
            'cta_demo'      => 'Try the live POS',
            'cta_buy'       => 'Get Hyper POS',
            'proof_own'     => 'No monthly fees',
            'proof_offline' => 'Offline-ready checkout',
            'proof_source'  => 'Full source code',
            'visual_label'  => 'Hyper POS cashier screen with live product signals',
            'cashier_alt'   => 'Hyper POS cashier screen',
            'signal_offline'=> 'Offline sync',
            'signal_layouts'=> '3 cashier layouts',
            'signal_stores' => 'Multi-store stock',
            'live'          => 'Live system',
        ],

        'proof' => [
            'self_hosted' => ['title' => 'Your server. Your data.', 'body' => 'Private and self-hosted'],
            'offline'     => ['title' => 'Internet down? Keep selling.', 'body' => 'Orders sync automatically'],
            'multistore'  => ['title' => 'One view for every store', 'body' => 'Stock and sales stay connected'],
            'no_fees'     => ['title' => 'Pay once. Own it.', 'body' => 'No per-user monthly charge'],
        ],

        'signature' => [
            'eyebrow'  => 'Operational features',
            'title'    => 'Practical tools for everyday store operations.',
            'subtitle' => 'Keep checkout moving, track stock accurately, and reduce manual work.',
            'offline' => [
                'kicker' => 'Connection-proof',
                'title'  => 'The queue never stops.',
                'body'   => 'Take orders offline and let Hyper POS safely sync them when the connection returns.',
                'queued' => 'Sale queued',
                'synced' => 'Synced',
            ],
            'locations' => [
                'kicker' => 'Shelf-level stock',
                'title'  => 'Know exactly where it is.',
                'body'   => 'Track inventory by aisle, fridge, rack, or godown—not just by store.',
                'aisle'  => 'Aisle',
                'fridge' => 'Fridge',
                'godown' => 'Godown',
            ],
            'layouts' => [
                'kicker' => 'Cashier choice',
                'title'  => 'One POS. Three selling styles.',
                'body'   => 'Switch the checkout layout to match a counter, fast lane, or visual catalog.',
                'beam'   => 'Beam',
                'lane'   => 'Lane',
                'counter'=> 'Counter',
            ],
            'fefo' => [
                'kicker' => 'Smart batch picking',
                'title'  => 'Sell what expires first.',
                'body'   => 'FEFO suggestions help reduce waste while keeping batch selection transparent.',
                'batch'  => 'Batch B-104',
                'expiry' => 'Expires first',
                'pick'   => 'Pick this',
            ],
            'display' => [
                'kicker' => 'Customer-facing checkout',
                'title'  => 'Turn the second screen into trust.',
                'body'   => 'Show the live cart, total, and a payment QR while the cashier completes the sale.',
                'cart'   => 'Live cart',
                'pay'    => 'Scan to pay',
            ],
        ],

        'flow' => [
            'eyebrow'  => 'Checkout workflow',
            'title'    => 'A clear process from scan to receipt.',
            'subtitle' => 'The cashier follows four familiar steps, making training and daily checkout straightforward.',
            'scan'     => 'Scan',
            'sell'     => 'Build the cart',
            'pay'      => 'Take any payment',
            'receipt'  => 'Print or share',
        ],

        'industries' => [
            'eyebrow'    => 'Business workflows',
            'title'      => 'Choose the workflow that fits your business.',
            'subtitle'   => 'Use industry-specific tools without installing or maintaining separate software.',
            'tabs_label' => 'Choose a business type',
            'retail' => [
                'title'   => 'Retail',
                'eyebrow' => 'Fast, flexible retail',
                'headline'=> 'Variants, customers, and checkout in one clear view.',
                'body'    => 'Move from product search to payment quickly while keeping the details that matter.',
                'point_1' => 'Size and colour variants',
                'point_2' => 'Customer history and loyalty',
                'point_3' => 'Hold and resume carts',
                'alt'     => 'Hyper POS retail cashier screen',
            ],
            'grocery' => [
                'title'   => 'Grocery',
                'eyebrow' => 'Quick everyday selling',
                'headline'=> 'Weight, barcode, and fast checkout work together.',
                'body'    => 'Keep a busy grocery counter moving while stock updates behind every sale.',
                'point_1' => 'Weight and price barcode prompts',
                'point_2' => 'Quick product search',
                'point_3' => 'Low-stock visibility',
                'alt'     => 'Weighted product prompt in Hyper POS',
            ],
            'supermarket' => [
                'title'   => 'Supermarket',
                'eyebrow' => 'Multi-location control',
                'headline'=> 'See stock across shelves, stores, and warehouses.',
                'body'    => 'Handle large catalogs and busy lanes with clear stock movement and store visibility.',
                'point_1' => 'Fast lane-friendly layouts',
                'point_2' => 'Store and location inventory',
                'point_3' => 'Purchasing and stock history',
                'alt'     => 'Inventory levels in Hyper POS',
            ],
            'pharmacy' => [
                'title'   => 'Pharmacy',
                'eyebrow' => 'Safer medicine sales',
                'headline'=> 'Batch, expiry, and drug rules travel with every sale.',
                'body'    => 'Give the cashier the right safeguards at the exact moment they are needed.',
                'point_1' => 'FEFO batch selection',
                'point_2' => 'Drug schedule controls',
                'point_3' => 'Expiry and low-stock alerts',
                'alt'     => 'Drug schedule settings in Hyper POS',
            ],
        ],

        'commerce' => [
            'eyebrow'      => 'Online meets in-store',
            'title'        => 'Your web orders can move through the same stock system.',
            'subtitle'     => 'Connect Shopify and WooCommerce, allocate each order to a store, and keep fulfilment visible.',
            'sync'         => 'Sync orders and products',
            'allocate'     => 'Allocate to the right store',
            'fulfil'       => 'Fulfil with shared stock',
            'visual_label' => 'Shopify and WooCommerce connected to Hyper POS stores',
            'core'         => 'Hyper POS',
            'store_1'      => 'Main store',
            'store_2'      => 'City branch',
            'store_3'      => 'Warehouse',
        ],

        'dashboard' => [
            'eyebrow'  => 'The business pulse',
            'title'    => 'Open the dashboard and know what needs attention.',
            'subtitle' => 'Sales, stock, and store activity stay visual, current, and easy to act on.',
            'sales'    => 'Sales at a glance',
            'stock'    => 'Low-stock signals',
            'activity' => 'Store-by-store activity',
        ],

        'gallery' => [
            'eyebrow'    => 'Real product. Real screens.',
            'title'      => 'Look through the system before you buy it.',
            'subtitle'   => 'Every screen below comes from the working product—not a concept mockup.',
            'real_screen'=> 'Inside Hyper POS',
        ],

        'everything' => [
            'eyebrow'     => 'And the full toolkit',
            'title'       => 'The essentials are already built in.',
            'subtitle'    => 'Compact where it should be, complete where it matters.',
            'loyalty'     => 'Customers & loyalty',
            'quotations'  => 'Quotations',
            'accounting'  => 'Cash & accounting',
            'suppliers'   => 'Purchases & suppliers',
            'blind_counts'=> 'Blind stock counts',
            'kiosk'       => 'Self-order kiosk',
            'refunds'     => 'Returns & refunds',
            'tax'         => 'Flexible tax',
            'roles'       => 'Roles & permissions',
            'reports'     => 'Reports & alerts',
            'imports'     => 'CSV / Excel imports',
            'backups'     => 'Backups & updates',
        ],
    ],

    'v3' => [
        'mobile_menu' => [
            'open' => 'Open navigation menu',
        ],
        'hero' => [
            'integrations_label' => 'Commerce and messaging integrations',
            'shopify_title'      => 'Shopify Integration',
            'shopify_body'       => 'Sync products, inventory, and orders.',
            'woo_title'          => 'WooCommerce Integration',
            'woo_body'           => 'Keep your POS and web store synchronized.',
            'whatsapp_title'     => 'WhatsApp Integration',
            'whatsapp_body'      => 'Send receipts, quotations, statements, and payment documents.',
            'cta_features'       => 'Explore Features',
            'shopify_connected'  => 'Shopify Connected',
            'woo_connected'      => 'WooCommerce Connected',
            'whatsapp_connected' => 'WhatsApp Connected',
            'offline_ready'      => 'Offline Ready',
            'inventory_synced'   => 'Inventory Synced',
            'sales_updated'      => 'Sales Updated',
        ],
        'connect' => [
            'eyebrow'      => 'Connected commerce',
            'title'        => 'Your POS. Your Online Store. One Connected System.',
            'subtitle'     => 'Bring physical and online selling into the same operational flow.',
            'visual_label' => 'Shopify and WooCommerce synchronizing with Hyper POS',
            'online_store' => 'Online store',
            'one_system'   => 'One source of truth',
            'products'     => 'Products',
            'inventory'    => 'Inventory',
            'orders'       => 'Orders',
            'customers'    => 'Customers',
            'pricing'      => 'Pricing',
        ],
        'why' => [
            'eyebrow'  => 'Product overview',
            'title'    => 'Run sales, stock, and reporting from one place.',
            'subtitle' => 'Four core workflows show how Hyper POS supports daily store operations.',
            'sell' => [
                'kicker' => 'Powerful cashier',
                'title'  => 'Sell faster',
                'body'   => 'Search, scan, discount, select a customer, take payment, and issue the receipt.',
                'alt'    => 'Hyper POS cashier overview',
            ],
            'control' => [
                'kicker' => 'Smart inventory',
                'title'  => 'Stay in control',
                'body'   => 'Watch quantities, purchasing, adjustments, and movement across locations.',
                'alt'    => 'Hyper POS inventory levels',
            ],
            'anywhere' => [
                'kicker' => 'Online + offline cashier',
                'title'  => 'Keep selling without internet',
                'body'   => 'Cashiers can create and complete orders even when the internet is down. Sales are saved locally and sync automatically when the connection returns.',
                'alt'    => 'Hyper POS offline sales queue',
            ],
            'know' => [
                'kicker' => 'Reports & analytics',
                'title'  => 'Know your business',
                'body'   => 'Read sales, revenue, profit, expenses, stock, and best sellers at a glance.',
                'alt'    => 'Hyper POS sales report',
            ],
        ],
        'offline' => [
            'internet_off' => 'Internet off',
            'keep_selling' => 'Keep selling',
            'stored_local' => 'Saved locally',
            'internet_on'  => 'Internet on',
            'auto_sync'    => 'Auto synced',
        ],
        'pricing_visual' => [
            'kicker'        => 'Margin-aware',
            'title'         => 'Automatic pricing',
            'body'          => 'Update the selling price from purchase cost and your configured margin.',
            'example_label' => 'Example automatic selling-price calculation',
            'cost'          => 'Purchase cost',
            'margin'        => 'Margin',
            'selling'       => 'Selling price',
        ],
        'inventory' => [
            'kicker'      => 'Every unit accounted for',
            'title'       => 'Stock moves as one continuous story.',
            'body'        => 'Purchases add it, sales reduce it, and every adjustment stays visible.',
            'tracking'    => 'Stock tracking',
            'purchasing'  => 'Purchasing',
            'adjustments' => 'Adjustments',
            'low_stock'   => 'Low-stock alerts',
            'history'     => 'Inventory history',
            'stock'       => 'Opening stock',
            'purchase'    => 'Purchase',
            'sale'        => 'POS sale',
            'remaining'   => 'Remaining',
        ],
        'workflow' => [
            'eyebrow'   => 'The complete business cycle',
            'title'     => 'One workflow from product setup to performance report.',
            'subtitle'  => 'Every step feeds the next, so sales and stock stay connected.',
            'product'   => 'Product',
            'purchase'  => 'Purchase',
            'inventory' => 'Inventory',
            'pos_sale'  => 'POS sale',
            'payment'   => 'Payment',
            'receipt'   => 'Receipt',
            'report'    => 'Report',
        ],
        'unified' => [
            'eyebrow'        => 'Always connected where it matters',
            'title'          => 'Online and offline become one business system.',
            'subtitle'       => 'The counter keeps moving locally while connected channels stay synchronized when the network is available.',
            'online_kicker'  => 'Connected channels',
            'online'         => 'Online',
            'online_orders'  => 'Online orders',
            'inventory_sync' => 'Shared inventory sync',
            'offline_kicker' => 'Resilient counter',
            'offline'        => 'Offline',
            'local_data'     => 'Local product data',
            'saved_here'     => 'Available at checkout',
            'offline_sales'  => 'Offline sales queue',
            'automatic_sync' => 'Automatic reconnection sync',
            'one_system'     => 'One business system',
        ],
        'mid_cta' => [
            'text'   => 'Want to see the real cashier and reports in action?',
            'button' => 'Open the live demo',
        ],
        'trust' => [
            'eyebrow' => 'Built for confident ownership',
            'title'   => 'Integrations, payments, and architecture you control.',
            'private' => 'Self-hosted and private',
            'owned'   => 'Full source code',
            'modern'  => 'Modern, update-ready stack',
        ],
    ],

    'v4' => [
        'visual' => [
            'cashier'     => 'Cashier',
            'search'      => 'Search or scan',
            'cart'        => 'Current cart',
            'pay'         => 'Take payment',
            'inventory'   => 'Inventory control',
            'pos'         => 'Hyper POS',
            'online'      => 'Connected',
            'offline'     => 'Offline ready',
            'saved_local' => 'Sales saved locally',
            'internet_off'=> 'Internet offline',
            'cashier_active' => 'Cashier stays active',
            'new_order'   => 'New order',
            'total'       => 'Total',
            'order_created' => 'Complete order offline',
            'saved'       => 'Saved',
            'create_sale' => 'Create sale',
            'auto_sync'   => 'Auto-sync online',
            'reports'     => 'Business overview',
            'sales'       => 'Sales',
            'profit'      => 'Profit',
            'stock'       => 'Stock',
        ],
    ],

    'global' => [
        'payments_eyebrow' => 'Get paid',
        'payments_title'   => 'Take payment any way your customers like.',
        'payments_body'    => 'Cash, cards, part-cash part-card, and quick QR payments — with popular online gateways built in and ready to switch on.',

        'world_eyebrow'    => 'Made for everyone',
        'world_title'      => 'Ready for shops anywhere in the world.',
        'world_body'       => 'Use it in your own language and currency, set your time zone, and run it comfortably in right-to-left languages too.',
        'chip_languages'   => 'Any language',
        'chip_rtl'         => 'Right-to-left ready',
        'chip_timezones'   => 'Your time zone',
    ],

    'mock' => [
        'search' => 'Search or scan a product…',
        'total'  => 'Total',
        'amount' => '$148.50',
        'pay'    => 'Charge',
        'badge'  => 'Works offline',
    ],

    'stats' => [
        'gateways'        => 'Payment gateways',
        'gateways_val'    => '5',
        'stores'          => 'Stores & locations',
        'stores_val'      => 'Multi',
        'inventory'       => 'Stock & inventory',
        'inventory_val'   => 'Live',
        'offline'         => 'Works offline',
        'offline_val'     => '100%',
        'languages'       => 'Languages & RTL',
        'languages_val'   => 'Any',
        'monthly_fees'    => 'Monthly fees',
        'monthly_fees_val'=> 'Zero',
    ],

    'features' => [
        'eyebrow'  => 'Features',
        'title'    => 'Everything you need to make a sale — and run the whole shop.',
        'subtitle' => 'One simple app. Nothing extra to buy, no charge per person, no surprises.',
        'explore'  => 'Explore more features',

        'offline' => [
            'title' => 'Sell even without internet',
            'body'  => 'If your connection drops, you can keep ringing up sales. Everything saves safely and catches up on its own once you\'re back online.',
        ],
        'multi_industry' => [
            'title' => 'Made for all kinds of shops',
            'body'  => 'Whether you run a regular store, a supermarket, or a pharmacy, the app is ready to work the way your business does — right out of the box.',
        ],
        'realtime' => [
            'title' => 'All your counters stay in sync',
            'body'  => 'Sales and stock update across every checkout at the same time, so your numbers are always right — no need to refresh anything.',
        ],
        'self_hosted' => [
            'title' => 'Runs on your own hosting',
            'body'  => 'No costly setup and no tech team needed. A simple one-click installer gets you going on the hosting you already pay for.',
        ],
        'extensible' => [
            'title' => 'Easy to grow later',
            'body'  => 'Need something extra down the road? The app is built so new features and add-ons slot in cleanly — without breaking what already works.',
        ],
        'inventory' => [
            'title' => 'Stock you can trust',
            'body'  => 'See what you have, move stock between locations, fix counts, and keep an eye on expiry dates — all in one place.',
        ],
        'payments' => [
            'title' => 'Take payment any way',
            'body'  => 'Cash, cards, part-cash part-card, and quick QR payments — let customers pay however is easiest for them.',
        ],
        'compliance' => [
            'title' => 'Tax and rules taken care of',
            'body'  => 'Built-in tax handling, expiry tracking, and pharmacy rules help you stay on the right side of the law — no extra tools required.',
        ],
    ],

    'showcase' => [
        'eyebrow' => 'One clear view',
        'title'   => 'See how your shop is doing at a glance.',
        'body'    => 'Today\'s sales, busy hours, low stock, and your best-selling lines — all on one simple dashboard, updated as you sell.',
        'alt'     => 'The dashboard screen',
    ],

    'steps' => [
        'eyebrow'  => 'Try it now',
        'title'    => 'Looking around the demo takes a minute.',
        'subtitle' => 'This is a real, fully set-up shop — feel free to click around as much as you like.',

        'signin' => [
            'title' => 'Sign in with a sample account',
            'body'  => 'Open the demo and use the ready-made owner or cashier login — one tap fills in the details for you.',
        ],
        'explore' => [
            'title' => 'Make a sale and check the reports',
            'body'  => 'Try the checkout screen, add a few products and stock, take a payment, and browse the dashboards and reports.',
        ],
        'reset' => [
            'title' => 'It all resets every night',
            'body'  => 'Change anything you like — the demo tidies itself back to a fresh, fully-stocked shop each night, so nothing you do sticks.',
        ],
    ],

    'industries' => [
        'eyebrow'  => 'Who it\'s for',
        'title'    => 'Set up for the way you sell.',
        'subtitle' => 'Pick your type of shop when you start, and the app adjusts to fit.',

        'retail' => [
            'title' => 'Retail stores',
            'body'  => 'Clothing, electronics, and everyday goods. Handle sizes and colours, scan barcodes, and check customers out quickly.',
        ],
        'supermarket' => [
            'title' => 'Supermarkets',
            'body'  => 'Sell items by weight, read price stickers straight from the scale, and keep big product lists fast and easy to search.',
        ],
        'pharmacy' => [
            'title' => 'Pharmacies',
            'body'  => 'Track batches and expiry dates, follow medicine rules, and always sell the stock that expires soonest first.',
        ],
    ],

    // Comprehensive "everything included" feature grid. Labels are short
    // (2–3 words). Keep this list to features that genuinely ship.
    'grid' => [
        'eyebrow'  => 'Everything included',
        'title'    => 'One app. Every tool your shop needs.',
        'subtitle' => 'No add-ons to buy and nothing locked behind a higher tier — it\'s all in the box.',
        'more'     => '& many more',
        'items' => [
            'offline'      => 'Offline-first checkout',
            'barcode'      => 'Barcode scanning',
            'multistore'   => 'Multi-store & locations',
            'gateways'     => 'Online payment gateways',
            'split'        => 'Split payments',
            'refunds'      => 'Returns & refunds',
            'hold'         => 'Hold & resume sales',
            'shifts'       => 'Cash shifts & X/Z reports',
            'weight'       => 'Sell by weight',
            'discounts'    => 'Order discounts',
            'customers'    => 'Customers & groups',
            'credit'       => 'Store credit & dues',
            'variants'     => 'Product variants',
            'kits'         => 'Kits & bundles',
            'batches'      => 'Batch & expiry tracking',
            'transfers'    => 'Stock transfers',
            'purchases'    => 'Purchases & suppliers',
            'counts'       => 'Stock counts & adjustments',
            'lowstock'     => 'Low-stock alerts',
            'tax'          => 'Flexible tax engine',
            'roles'        => 'Roles & permissions',
            'dashboard'    => 'Dashboard & insights',
            'importexport' => 'CSV / Excel import & export',
            'languages'    => 'Multi-language & RTL',
            'darkmode'     => 'Dark mode',
            'backups'      => 'Backups & updates',
            'pwa'          => 'Install as an app',
            'notifications'=> 'Real-time notifications',
        ],
    ],

    'gallery' => [
        'eyebrow'  => 'A look inside',
        'title'    => 'See the app from every angle.',
        'subtitle' => 'Real screens from the admin — products, stock, purchases, reports, and more.',
        'prev'     => 'Previous screenshot',
        'next'     => 'Next screenshot',
        'close'    => 'Close',
    ],

    'tech' => [
        'eyebrow'  => 'Under the hood',
        'title'    => 'Built with modern, reliable technology.',
        'subtitle' => 'A clean, well-known stack — fast, secure, and easy for any developer to customize.',
    ],

    // CodeCanyon license comparison. IMPORTANT: confirm these rows match
    // your actual Regular vs Extended terms before publishing.
    'pricing' => [
        'eyebrow'   => 'Licensing',
        'title'     => 'Find the right license for your business.',
        'subtitle'  => 'A one-time payment on CodeCanyon — pick the license that fits how you\'ll use it.',
        'col_feature'  => 'What\'s included',
        'col_regular'  => 'Regular License',
        'col_extended' => 'Extended License',
        'recommended'  => 'Best value',
        'band_text'    => 'Building it for a client, or charging your own users? Choose the Extended License.',
        'band_cta'     => 'Get the Extended License',
        'rows' => [
            'lifetime'   => 'Lifetime license validity',
            'domain'     => 'Use on your own install',
            'support'    => '6 months of support',
            'premium'    => 'All features included',
            'updates'    => 'Free lifetime updates',
            'source'     => 'Full source code',
            'personal'   => 'For your own single business',
            'install'    => 'Free one-time installation',
            'remote'     => 'Remote setup help (AnyDesk)',
            'priority'   => '1 year priority support',
            'branding'   => 'Free branding / logo setup',
            'commercial' => 'For client / commercial projects',
        ],
    ],

    'help' => [
        'title' => 'Need help with setup or customization?',
        'body'  => 'Our team can install it for you, set up your branding, configure features, and tailor it to your business — so you can focus on selling.',
        'cta'   => 'Talk to our expert',
    ],

    'ebook' => [
        'eyebrow'  => 'Free guide',
        'title'    => 'Get your free POS eBook',
        'subtitle' => 'Fill in your details for instant access to a practical guide for choosing, setting up, and growing with a modern point of sale.',
        'fields' => [
            'name'        => 'Full name',
            'email'       => 'Email address',
            'country'     => 'Country',
            'country_hint' => 'Select your country',
        ],
        'button'      => 'Get the eBook now',
        'submitting'  => 'Preparing your download…',
        'honeypot'    => 'Website',
        'privacy'     => 'Your details are stored privately on this server and are not included in the download.',
        'not_ready'   => 'The eBook is being prepared. Please check back shortly.',
        'errors' => [
            'unavailable' => 'The eBook is temporarily unavailable. Please try again later.',
            'submit'      => 'We could not save your details. Please try again.',
            'download'    => 'The download could not be started. Please try again.',
        ],
        'export' => [
            'columns' => [
                'requested_at' => 'Requested at',
                'name'         => 'Name',
                'email'        => 'Email',
                'country_code' => 'Country code',
                'country'      => 'Country',
            ],
        ],
    ],

    'cta' => [
        'title'     => 'Ready to run your store with Hyper POS?',
        'subtitle'  => 'Manage counters, products, stock movements, and online orders in one system you own.',
        'button'    => 'Keep exploring the demo',
        'buy'       => 'Get it on CodeCanyon',
        'note'      => 'One-time payment · Full source code · Self-hosted and private',
        'secondary' => 'Visit us',
    ],

    'footer' => [
        'tagline'  => 'A point of sale you own outright — no monthly fees, ever.',
        'docs'     => 'Documentation',
        'buy'      => 'Get it on CodeCanyon',
        'product'  => 'Visit us',
        'privacy'  => 'Privacy policy',
        'terms'    => 'Terms of service',
        'rights'   => 'All rights reserved.',
        'made_by'  => 'Made with :heart by :company',
    ],

];
