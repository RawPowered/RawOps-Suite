<?php
/**
 * Admin Simulation Header Component
 * Displays a sticky header when admin is simulating a role
 */

// Only show if admin simulation is active
if (isset($_SESSION['admin_simulation']) && current_admin_id()) {
    $simulation = $_SESSION['admin_simulation'];
    $roleName = ucfirst(str_replace('_', ' ', $simulation['simulated_role']));
    $startTime = $simulation['started_at'];
    $duration = time() - $startTime;
    $durationText = '';
    
    if ($duration < 60) {
        $durationText = $duration . 's';
    } elseif ($duration < 3600) {
        $durationText = floor($duration / 60) . 'm ' . ($duration % 60) . 's';
    } else {
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $durationText = $hours . 'h ' . $minutes . 'm';
    }
    ?>
    
    <!-- Admin Role Simulation Header -->
    <div id="admin-simulation-header" style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 10000;
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: white;
        padding: 12px 20px;
        border-bottom: 3px solid #991b1b;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 14px;
        font-weight: 500;
        animation: slideDown 0.3s ease-out;
    ">
        <div style="
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        ">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="
                    background: rgba(255, 255, 255, 0.2);
                    border-radius: 8px;
                    padding: 6px 12px;
                    font-size: 16px;
                    font-weight: 600;
                ">
                    🎭 ADMIN SIMULATION
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span>Simulating:</span>
                    <strong style="
                        background: rgba(255, 255, 255, 0.15);
                        border-radius: 6px;
                        padding: 4px 8px;
                        font-size: 13px;
                    "><?php echo htmlspecialchars($roleName); ?></strong>
                    <span style="opacity: 0.8; font-size: 12px;">
                        (<?php echo $durationText; ?>)
                    </span>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 12px;">
                <!-- Quick Role Switch -->
                <div class="quick-switch" style="display: flex; gap: 6px; align-items: center;">
                    <span style="opacity: 0.9; font-size: 12px;">Quick Switch:</span>
                    <button onclick="adminSimulation.switchRole('sales_agent')" 
                            class="quick-role-btn" 
                            title="Switch to Sales Agent"
                            style="
                                background: <?php echo $simulation['simulated_role'] === 'sales_agent' ? 'rgba(255,255,255,0.3)' : 'rgba(255,255,255,0.1)'; ?>;
                                color: white;
                                border: 1px solid rgba(255,255,255,0.3);
                                border-radius: 4px;
                                padding: 4px 8px;
                                font-size: 11px;
                                cursor: pointer;
                                transition: all 0.2s;
                            ">💼 Sales</button>
                    
                    <button onclick="adminSimulation.switchRole('trainee')" 
                            class="quick-role-btn"
                            title="Switch to Trainee"
                            style="
                                background: <?php echo $simulation['simulated_role'] === 'trainee' ? 'rgba(255,255,255,0.3)' : 'rgba(255,255,255,0.1)'; ?>;
                                color: white;
                                border: 1px solid rgba(255,255,255,0.3);
                                border-radius: 4px;
                                padding: 4px 8px;
                                font-size: 11px;
                                cursor: pointer;
                                transition: all 0.2s;
                            ">🎓 Trainee</button>
                    
                    <button onclick="adminSimulation.switchRole('customer')" 
                            class="quick-role-btn"
                            title="Switch to Customer"
                            style="
                                background: <?php echo $simulation['simulated_role'] === 'customer' ? 'rgba(255,255,255,0.3)' : 'rgba(255,255,255,0.1)'; ?>;
                                color: white;
                                border: 1px solid rgba(255,255,255,0.3);
                                border-radius: 4px;
                                padding: 4px 8px;
                                font-size: 11px;
                                cursor: pointer;
                                transition: all 0.2s;
                            ">🛒 Customer</button>
                </div>
                
                <!-- Return to Admin Button -->
                <button onclick="adminSimulation.endSimulation()" style="
                    background: linear-gradient(135deg, #059669, #047857);
                    color: white;
                    border: none;
                    border-radius: 8px;
                    padding: 8px 16px;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                    transition: all 0.2s;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                " onmouseover="this.style.background='linear-gradient(135deg, #047857, #065f46)'"
                   onmouseout="this.style.background='linear-gradient(135deg, #059669, #047857)'">
                    <span>↩️</span>
                    Return To Admin Mode
                </button>
            </div>
        </div>
    </div>
    
    <!-- Push content down to account for fixed header -->
    <div style="height: 60px;"></div>
    
    <style>
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .quick-role-btn:hover {
            background: rgba(255, 255, 255, 0.25) !important;
            transform: translateY(-1px);
        }
        
        .quick-role-btn:active {
            transform: translateY(0);
        }
        
        @media (max-width: 768px) {
            #admin-simulation-header {
                padding: 10px 15px;
            }
            
            #admin-simulation-header > div {
                flex-direction: column;
                gap: 12px;
            }
            
            .quick-switch {
                display: none !important;
            }
        }
    </style>
    
    <script>
        // Admin Simulation JavaScript Functions
        window.adminSimulation = {
            switchRole: function(role) {
                if (confirm('Switch to ' + role.replace('_', ' ') + ' role?')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = (window.__BASE_PREFIX||'') + '/admin/role-simulator.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'simulate_role';
                    form.appendChild(actionInput);
                    
                    const roleInput = document.createElement('input');
                    roleInput.type = 'hidden';
                    roleInput.name = 'role';
                    roleInput.value = role;
                    form.appendChild(roleInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            },
            
            endSimulation: function() {
                if (confirm('End role simulation and return to admin mode?')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = (window.__BASE_PREFIX||'') + '/admin/role-simulator.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'end_simulation';
                    form.appendChild(actionInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        };
        
        // Auto-refresh duration every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
    </script>
    
    <?php
}
?>
