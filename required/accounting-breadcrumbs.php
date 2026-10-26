<?php
/**
 * Accounting Breadcrumbs Navigation
 * Provides consistent breadcrumb navigation across all accounting pages
 */

// Ensure required functions are available
if (!function_exists('outbound_path')) {
    function outbound_path($path) {
        return $path;
    }
}

if (!function_exists('render_accounting_breadcrumbs')) {
    /**
     * Render breadcrumbs for accounting pages
     * @param array $crumbs Array of breadcrumb items ['label' => 'Text', 'url' => 'path']
     */
    function render_accounting_breadcrumbs(array $crumbs = []): void {
        echo '<style>
            .acct1015_breadcrumb { 
                display: flex; 
                padding: 0.75rem 1rem; 
                margin-bottom: 1rem; 
                list-style: none; 
                background-color: #f8f9fa; 
                border-radius: 0.375rem;
                font-size: 0.9rem;
            }
            .acct1015_breadcrumb_item { display: inline-block; }
            .acct1015_breadcrumb_item + .acct1015_breadcrumb_item::before {
                content: "›";
                padding: 0 0.5rem;
                color: #6c757d;
            }
            .acct1015_breadcrumb_item.active { color: #6c757d; }
            .acct1015_breadcrumb_item a { 
                color: #0d6efd; 
                text-decoration: none; 
            }
            .acct1015_breadcrumb_item a:hover { 
                text-decoration: underline; 
            }
        </style>';
        
        echo '<nav aria-label="breadcrumb">';
        echo '<ol class="acct1015_breadcrumb">';
        
        // Always start with Accounting Dashboard
        echo '<li class="acct1015_breadcrumb_item"><a href="' . outbound_path('/admin/accounting/') . '"><i class="bi bi-calculator-fill"></i> Accounting</a></li>';
        
        // Add custom crumbs
        $lastIndex = count($crumbs) - 1;
        foreach ($crumbs as $index => $crumb) {
            $isLast = ($index === $lastIndex);
            $class = $isLast ? 'acct1015_breadcrumb_item active' : 'acct1015_breadcrumb_item';
            
            if ($isLast || empty($crumb['url'])) {
                echo '<li class="' . $class . '" aria-current="page">' . htmlspecialchars($crumb['label']) . '</li>';
            } else {
                echo '<li class="' . $class . '"><a href="' . htmlspecialchars($crumb['url']) . '">' . htmlspecialchars($crumb['label']) . '</a></li>';
            }
        }
        
        echo '</ol>';
        echo '</nav>';
    }
}

if (!function_exists('render_accounting_secondary_nav')) {
    /**
     * Render secondary navigation bar for accounting section
     */
    function render_accounting_secondary_nav(string $currentPage = ''): void {
        // Brand colors: Gold (#d97706, #fbbf24), Blue (#1e3a8a, #3b82f6), Green (#047857, #10b981), Silver (#6b7280, #9ca3af)
        $navItems = [
            'index.php' => ['icon' => 'house-fill', 'label' => 'Dashboard'],
            'journal.php' => ['icon' => 'journal-text', 'label' => 'Journal'],
            'ledger.php' => ['icon' => 'list-ul', 'label' => 'Ledger'],
            'taxes.php' => ['icon' => 'calculator', 'label' => 'Taxes'],
            '1099-management.php' => ['icon' => 'file-earmark-text', 'label' => '1099s'],
            'expense-approvals.php' => ['icon' => 'check2-square', 'label' => 'Approvals'],
            'expense-allocations.php' => ['icon' => 'diagram-3', 'label' => 'Allocations'],
            'sync-controller.php' => ['icon' => 'arrow-repeat', 'label' => 'Sync'],
            'reconciliation.php' => ['icon' => 'check-circle', 'label' => 'Reconcile'],
            'report-designer.php' => ['icon' => 'file-earmark-bar-graph', 'label' => 'Reports'],
            'incinerator.php' => ['icon' => 'fire', 'label' => 'Incinerator']
        ];
        
        echo '<style>
            .acct1015_nav { 
                display: flex; 
                flex-wrap: wrap; 
                gap: 0.5rem; 
                padding: 1rem; 
                background: white; 
                border-radius: 0.5rem; 
                box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
                margin-bottom: 1.5rem;
            }
            .acct1015_nav_btn {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.5rem 1rem;
                border: 2px solid #e9ecef;
                border-radius: 0.375rem;
                background: white;
                color: #495057;
                text-decoration: none;
                font-size: 0.875rem;
                font-weight: 500;
                transition: all 0.2s;
            }
            .acct1015_nav_btn:hover {
                background: #f8f9fa;
                border-color: #dee2e6;
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .acct1015_nav_btn.active {
                background: linear-gradient(135deg, #d97706 0%, #fbbf24 100%); /* Gold gradient */
                border-color: #d97706;
                color: white;
                font-weight: 600;
                box-shadow: 0 4px 6px rgba(217,119,6,0.35);
            }
        </style>';
        
        echo '<div class="acct1015_nav">';
        
        foreach ($navItems as $page => $item) {
            $isActive = ($currentPage === $page);
            $btnClass = $isActive ? 'acct1015_nav_btn active' : 'acct1015_nav_btn';
            
            echo '<a href="' . htmlspecialchars($page) . '" class="' . $btnClass . '">';
            echo '<i class="bi bi-' . $item['icon'] . '" style="font-size: 1.1rem;"></i>';
            echo '<span>' . $item['label'] . '</span>';
            echo '</a>';
        }
        
        echo '</div>';
    }
}

if (!function_exists('render_agent_financial_nav')) {
    /**
     * Render navigation for sales agent financial pages
     */
    function render_agent_financial_nav(string $currentPage = ''): void {
        // Brand colors: Gold, Blue, Green
        $navItems = [
            'my-financials.php' => ['icon' => 'graph-up', 'label' => 'My Financials', 'gradient' => 'linear-gradient(135deg, #047857 0%, #10b981 100%)'], /* Green */
            'books-detail.php' => ['icon' => 'book', 'label' => 'My Books', 'gradient' => 'linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%)'], /* Blue */
            'my-1099.php' => ['icon' => 'file-earmark-text', 'label' => '1099 Info', 'gradient' => 'linear-gradient(135deg, #d97706 0%, #fbbf24 100%)'] /* Gold */
        ];
        
        echo '<style>
            .acct1015_agent_nav {
                display: flex;
                gap: 0.75rem;
                margin-bottom: 1.5rem;
                padding: 1rem;
                background: white;
                border-radius: 0.5rem;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .acct1015_agent_btn {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                padding: 1rem;
                border: 2px solid #e9ecef;
                border-radius: 0.5rem;
                background: white;
                color: #495057;
                text-decoration: none;
                font-weight: 500;
                transition: all 0.3s;
            }
            .acct1015_agent_btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            }
            .acct1015_agent_btn.active {
                border: none;
                color: white;
                font-weight: 600;
                box-shadow: 0 6px 12px rgba(0,0,0,0.2);
            }
            .acct1015_agent_btn i { font-size: 1.25rem; }
        </style>';
        
        echo '<div class="acct1015_agent_nav">';
        
        foreach ($navItems as $page => $item) {
            $isActive = ($currentPage === $page);
            $style = $isActive ? 'background: ' . $item['gradient'] . ';' : '';
            
            echo '<a href="' . htmlspecialchars($page) . '" class="acct1015_agent_btn ' . ($isActive ? 'active' : '') . '" style="' . $style . '">';
            echo '<i class="bi bi-' . $item['icon'] . '"></i>';
            echo '<span>' . $item['label'] . '</span>';
            echo '</a>';
        }
        
        echo '</div>';
    }
}

