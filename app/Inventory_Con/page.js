"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import Dashboard from "./Dashboard";
import InventoryTransfer from "./InventoryTransfer";
import Warehouse from "./Warehouse";
import ConvenienceStore from "./ConvenienceStore";
import PharmacyInventory from "./PharmacyInventory";
import CreatePurchaseOrder from "./CreatePurchaseOrder";
import StockAdjustment from "./StockAdjustment";
import Reports from "./Reports";
import ReturnManagement from "./ReturnManagement";
import MovementHistory from "./MovementHistory";
import Archive from "./Archive";
import Settings from "./Settings";
import Sidebar from "./sidebar";
import LogoutConfirm from "./LogoutConfirm";
import { ThemeProvider } from "./ThemeContext";
import { NotificationProvider } from "./NotificationContext";
import { AlertManagerProvider } from "./AlertManager";
import { SettingsProvider } from "./SettingsContext";
import ThemeToggle from "./ThemeToggle";

export default function InventoryPage() {
  const [activeComponent, setActiveComponent] = useState("Dashboard");
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
  const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const router = useRouter();

  // Check if user is logged in
  useEffect(() => {
    const userData = sessionStorage.getItem('user_data');
    if (!userData) {
      router.push('/');
      return;
    }

    const user = JSON.parse(userData);
    // Check if user has inventory-related role
    const role = user.role?.toLowerCase() || '';
    if (!role.includes('inventory') && !role.includes('pharmacist') && !role.includes('admin')) {
      router.push('/');
      return;
    }
  }, [router]);

  const componentMap = {
    Dashboard: <Dashboard />,
    InventoryTransfer: <InventoryTransfer />,
    Warehouse: <Warehouse />,
    ConvenienceStore: <ConvenienceStore />,
    PharmacyInventory: <PharmacyInventory />,
    CreatePurchaseOrder: <CreatePurchaseOrder />,
    StockAdjustment: <StockAdjustment />,
    Reports: <Reports />,
    ReturnManagement: <ReturnManagement />,
    MovementHistory: <MovementHistory />,
    Archive: <Archive />,
    Settings: <Settings />
  };

  const handleLogout = () => {
    setShowLogoutConfirm(true);
  };

  const confirmLogout = async () => {
    try {
      // Get user data from sessionStorage
      const userData = sessionStorage.getItem('user_data');
      const empId = userData ? JSON.parse(userData).user_id : null;
      
      console.log('Inventory Logout attempt - User data:', userData);
      console.log('Inventory Logout attempt - Emp ID:', empId);
      
      // Call logout API
      const response = await fetch('http://localhost/caps2e2/Api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
          action: 'logout',
          emp_id: empId 
        })
      });
      
      const result = await response.json();
      console.log('Inventory Logout API response:', result);
      
      if (result.success) {
        console.log('Inventory logout successful');
      } else {
        console.error('Inventory logout failed:', result.message);
      }
    } catch (error) {
      console.error('Inventory logout error:', error);
    } finally {
      // Always clear session and redirect
      sessionStorage.removeItem('user_data');
      router.push('/');
    }
  };

  const cancelLogout = () => {
    setShowLogoutConfirm(false);
  };

  return (
    <ThemeProvider>
      <SettingsProvider>
        <NotificationProvider>
          <AlertManagerProvider>
            <div className="flex h-screen" style={{ backgroundColor: 'var(--inventory-bg-primary)' }}>
        {/* Mobile top bar */}
        <div className="fixed top-0 left-0 right-0 z-30 flex items-center justify-between px-4 py-3 md:hidden" style={{ backgroundColor: 'var(--inventory-bg-secondary)', borderBottom: '1px solid var(--inventory-border)' }}>
          <button
            onClick={() => setIsMobileSidebarOpen(true)}
            className="p-2 rounded"
            aria-label="Open menu"
            style={{ color: 'var(--inventory-text-primary)' }}
          >
            {/* simple hamburger */}
            <span className="block w-6 h-0.5 mb-1" style={{ backgroundColor: 'var(--inventory-text-primary)' }}></span>
            <span className="block w-6 h-0.5 mb-1" style={{ backgroundColor: 'var(--inventory-text-primary)' }}></span>
            <span className="block w-6 h-0.5" style={{ backgroundColor: 'var(--inventory-text-primary)' }}></span>
          </button>
          <div className="font-semibold" style={{ color: 'var(--inventory-text-primary)' }}>Inventory</div>
          <div className="w-8" />
        </div>

        {/* Sidebar */}
        <div className="flex-shrink-0">
          <Sidebar 
            activeComponent={activeComponent} 
            setActiveComponent={setActiveComponent}
            onLogout={handleLogout}
            isMobileOpen={isMobileSidebarOpen}
            onClose={() => setIsMobileSidebarOpen(false)}
            isCollapsed={isSidebarCollapsed}
            onToggleCollapse={() => setIsSidebarCollapsed(!isSidebarCollapsed)}
          />
        </div>

        {/* Mobile overlay */}
        {isMobileSidebarOpen && (
          <div
            onClick={() => setIsMobileSidebarOpen(false)}
            className="fixed inset-0 z-30 md:hidden"
            style={{ backgroundColor: 'rgba(0,0,0,0.1)' }}
          />
        )}
        
        {/* Main Content */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {/* Header */}

                  {/* Content Area */}
        <main className="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 pt-16 md:pt-6" style={{ backgroundColor: 'var(--inventory-bg-secondary)' }}>
          <div className="w-full min-w-0 overflow-x-auto md:overflow-visible">
            {componentMap[activeComponent] || <Dashboard />}
          </div>
        </main>
        
        {/* Theme Toggle Button */}
        <ThemeToggle />
        </div>

        {/* Logout Confirmation Modal */}
        {showLogoutConfirm && (
          <LogoutConfirm
            onConfirm={confirmLogout}
            onCancel={cancelLogout}
          />
        )}
        </div>
          </AlertManagerProvider>
        </NotificationProvider>
      </SettingsProvider>
    </ThemeProvider>
  );
}

