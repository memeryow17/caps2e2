import React, { useState, useEffect } from "react";
import axios from "axios";
import { toast } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";
import { getApiEndpointForAction } from '../../lib/apiHandler';
import apiHandler from '../../lib/apiHandler';
import {
  ChevronUp,
  ChevronDown,
  Plus,
  X,
  Search,
  Package,
  CheckCircle,
  AlertCircle,
  Clock,
  Bell,
  BellRing,
  History,
} from "lucide-react";
import { FaArchive } from "react-icons/fa";
import { useTheme } from "./ThemeContext";
import { useSettings } from "./SettingsContext";
import NotificationSystem from "./NotificationSystem";

function ConvenienceStore() {
  const { theme } = useTheme();
  const { settings, isProductExpiringSoon, isProductExpired, getExpiryStatus, isStockLow } = useSettings();
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(false);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [selectedProductType, setSelectedProductType] = useState("all");
  const [convenienceLocationId, setConvenienceLocationId] = useState(null);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [selectedItem, setSelectedItem] = useState(null);
  const [archiveLoading, setArchiveLoading] = useState(false);
  const [showBatchModal, setShowBatchModal] = useState(false);
  const [selectedProductForBatch, setSelectedProductForBatch] = useState(null);
  const [batchData, setBatchData] = useState([]);
  const [loadingBatch, setLoadingBatch] = useState(false);
  
  // Transfer history states
  const [showQuantityHistoryModal, setShowQuantityHistoryModal] = useState(false);
  const [selectedProductForHistory, setSelectedProductForHistory] = useState(null);
  const [quantityHistoryData, setQuantityHistoryData] = useState([]);
  const [showCurrentFifoData, setShowCurrentFifoData] = useState(false);
  const [fifoStockData, setFifoStockData] = useState([]);
  
  // Batch transfer states
  const [showBatchTransferModal, setShowBatchTransferModal] = useState(false);
  const [batchTransferData, setBatchTransferData] = useState([]);
  const [batchTransferSummary, setBatchTransferSummary] = useState({});
  const [loadingBatchTransfers, setLoadingBatchTransfers] = useState(false);
  
  
  // Notification states
  const [showNotifications, setShowNotifications] = useState(false);
  const [notifications, setNotifications] = useState({
    expiring: [],
    lowStock: [],
    outOfStock: []
  });
  const [alertCount, setAlertCount] = useState(0);

  // API function - Updated to use centralized API handler
  async function handleApiCall(action, data = {}) {
    try {
      const endpoint = getApiEndpointForAction(action);
      const response = await apiHandler.callAPI(endpoint, action, data);
      
      if (response && typeof response === "object") {
        if (!response.success) {
          console.warn("⚠️ API responded with failure:", response.message || response);
        }
        return response;
      } else {
        console.warn("⚠️ Unexpected API response format:", response);
        return {
          success: false,
          message: "Unexpected response format",
          data: response,
        };
      }
    } catch (error) {
      console.error("❌ API Call Error:", error);
      return {
        success: false,
        message: error.message,
        error: "REQUEST_ERROR",
      };
    }
  }

  // Calculate notifications for expiring and low stock products
  const calculateNotifications = (productList) => {
    const today = new Date();
    
    const expiring = productList.filter(product => {
      if (!product.expiration || !settings.expiryAlerts) return false;
      return isProductExpiringSoon(product.expiration) || isProductExpired(product.expiration);
    });
    
    const lowStock = productList.filter(product => {
      const quantity = parseInt(product.total_quantity || product.quantity || 0);
      return isStockLow(quantity) && settings.lowStockAlerts;
    });
    
    const outOfStock = productList.filter(product => {
      const quantity = parseInt(product.total_quantity || product.quantity || 0);
      return quantity === 0;
    });
    
    setNotifications({
      expiring: expiring.sort((a, b) => {
        const dateA = a.expiration && a.expiration !== 'null' ? new Date(a.expiration) : new Date(0);
        const dateB = b.expiration && b.expiration !== 'null' ? new Date(b.expiration) : new Date(0);
        return dateA - dateB;
      }),
      lowStock: lowStock.sort((a, b) => parseInt(a.quantity || 0) - parseInt(b.quantity || 0)),
      outOfStock
    });

    // Check for auto-reorder if enabled
    checkAutoReorder(productList);
  };

  // Auto-reorder functionality
  function checkAutoReorder(products) {
    if (!settings.autoReorder) return;
    
    const lowStockProducts = products.filter(product => {
      const quantity = parseInt(product.total_quantity || product.quantity || 0);
      return isStockLow(quantity);
    });
    
    if (lowStockProducts.length > 0) {
      const productNames = lowStockProducts.map(p => p.product_name).join(', ');
      toast.warning(`🔄 Auto-reorder: ${lowStockProducts.length} product(s) need restocking: ${productNames}`);
      
      // Here you could trigger an API call to create purchase orders
      // or send notifications to suppliers
      console.log("Auto-reorder triggered for products:", lowStockProducts);
    }
  }

  // Load convenience store location ID
  const loadConvenienceLocation = async () => {
    try {
      console.log("🔍 Loading locations...");
      const response = await handleApiCall("get_locations");
      console.log("📍 Locations API Response:", response);
      
      if (response.success && Array.isArray(response.data)) {
        console.log("📍 All Locations:", response.data);
        const convenienceLocation = response.data.find(loc => 
          loc.location_name.toLowerCase().includes('convenience')
        );
        if (convenienceLocation) {
          console.log("📍 Found convenience location:", convenienceLocation);
          setConvenienceLocationId(convenienceLocation.location_id);
        } else {
          console.warn("⚠️ No convenience store location found");
          console.warn("⚠️ Available locations:", response.data.map(loc => loc.location_name));
        }
        
        return convenienceLocation?.location_id || null;
      } else {
        console.warn("⚠️ Failed to load locations:", response.message);
      }
    } catch (error) {
      console.error("Error loading convenience location:", error);
    }
    return null;
  };

  // Load products for convenience store
  const loadProducts = async () => {
    if (!convenienceLocationId || loading) return; // Prevent multiple simultaneous calls
    
    setLoading(true);
    try {
      console.log("🔄 Loading convenience store products...");
      
      // First sync transferred products to ensure proper pricing
      try {
        await handleApiCall("sync_transferred_products", {
          location_name: "convenience"
        });
        console.log("✅ Synced transferred products for pricing");
      } catch (syncError) {
        console.warn("⚠️ Sync failed, continuing with load:", syncError);
      }
      
      // Use the new convenience store products API
      const response = await handleApiCall("get_convenience_products_fifo", {
        location_name: "convenience",
        search: searchTerm,
        category: selectedCategory,
        product_type: selectedProductType
      });
      
      console.log("📦 API Response:", response);
      console.log("🔍 Convenience Location ID:", convenienceLocationId);
      
      if (response.success && Array.isArray(response.data)) {
        console.log("✅ Loaded convenience store products:", response.data.length);
        console.log("📋 Raw Products Data:", response.data);
        
        // Filter out archived products
        const activeProducts = response.data.filter(
          (product) => (product.status || "").toLowerCase() !== "archived"
        );
        console.log("✅ Active convenience store products after filtering:", activeProducts.length);
        console.log("📋 Products:", activeProducts.map(p => `${p.product_name} (${p.quantity}) - ${p.product_type}`));
        
        // Debug SRP fields for first product
        if (activeProducts.length > 0) {
          const firstProduct = activeProducts[0];
          console.log("🔍 SRP Debug for first product:", {
            product_name: firstProduct.product_name,
            srp: firstProduct.srp,
            first_batch_srp: firstProduct.first_batch_srp,
            unit_price: firstProduct.unit_price,
            transfer_srp: firstProduct.transfer_srp,
            allFields: Object.keys(firstProduct)
          });
        }
        
        setProducts(activeProducts);
        calculateNotifications(activeProducts);
      } else {
        console.warn("⚠️ Primary API failed, trying fallback...");
        console.warn("⚠️ API Error:", response.message);
        
        // Fallback to the original backend API
        const fallbackResponse = await handleApiCall("get_products_by_location_name", {
          location_name: "convenience"
        });
        
        console.log("📦 Fallback API Response:", fallbackResponse);
        
        if (fallbackResponse.success && Array.isArray(fallbackResponse.data)) {
          console.log("✅ Loaded convenience store products (fallback):", fallbackResponse.data.length);
          // Filter out archived products
          const activeProducts = fallbackResponse.data.filter(
            (product) => (product.status || "").toLowerCase() !== "archived"
          );
          console.log("✅ Active convenience store products after filtering (fallback):", activeProducts.length);
          setProducts(activeProducts);
          calculateNotifications(activeProducts);
        } else {
          console.warn("⚠️ No products found for convenience store");
          console.warn("⚠️ Fallback Error:", fallbackResponse.message);
          setProducts([]);
        }
      }
    } catch (error) {
      console.error("Error loading products:", error);
      toast.error("Failed to load products");
      setProducts([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const initialize = async () => {
      const locationId = await loadConvenienceLocation();
      if (locationId) {
        await loadProducts();
      }
    };
    initialize();
  }, []); // Only run once on mount

  useEffect(() => {
    if (convenienceLocationId) {
      loadProducts();
    }
  }, [searchTerm, selectedCategory, selectedProductType, convenienceLocationId]);

  // Auto-refresh products every 30 seconds to catch new transfers and sales
  useEffect(() => {
    const interval = setInterval(() => {
      if (convenienceLocationId && !loading) {
        console.log("🔄 Auto-refreshing convenience store products...");
        const previousCount = products.length;
        const previousExpiringCount = notifications.expiring.length;
        
        loadProducts().then(() => {
          // Check if new products were added
          if (products.length > previousCount) {
            const newProducts = products.length - previousCount;
            toast.success(`🆕 ${newProducts} new product(s) transferred to convenience store!`);
          }
          
          // Check for new expiring products
          if (notifications.expiring.length > previousExpiringCount && settings.expiryAlerts) {
            const newExpiringProducts = notifications.expiring.length - previousExpiringCount;
            toast.warning(`⚠️ ${newExpiringProducts} product(s) expiring within ${settings.expiryWarningDays} days!`);
          }
        });
      }
    }, 30000); // 30 seconds - reduced frequency to prevent excessive calls

    return () => clearInterval(interval);
  }, [convenienceLocationId, loading, notifications.expiring.length, settings.expiryAlerts, settings.expiryWarningDays]);

  // Close notifications when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (showNotifications && !event.target.closest('.notification-dropdown')) {
        setShowNotifications(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [showNotifications]);

  // Listen for inventory refresh events (e.g., when returns are approved)
  useEffect(() => {
    const handleInventoryRefresh = (event) => {
      const { location, action, message, transferDetailsUpdated } = event.detail;
      
      // Check if this refresh is for Convenience Store
      if (location && location.toLowerCase().includes('convenience')) {
        console.log('🔄 Inventory refresh triggered for Convenience Store:', { action, message, transferDetailsUpdated });
        
        // Show toast notification with transfer details info
        let notificationMessage = `🔄 ${message} - Refreshing inventory...`;
        if (transferDetailsUpdated) {
          notificationMessage += `\n📊 Transfer details updated with returned quantities.`;
        }
        toast.success(notificationMessage);
        
        // Refresh products after a short delay
        setTimeout(() => {
          loadProducts();
        }, 1000);
      }
    };

    window.addEventListener('inventoryRefresh', handleInventoryRefresh);
    return () => {
      window.removeEventListener('inventoryRefresh', handleInventoryRefresh);
    };
  }, [convenienceLocationId]);

  const getStatusColor = (status) => {
    switch (status) {
      case "in stock":
        return "text-green-600 bg-green-100";
      case "low stock":
        return "text-yellow-600 bg-yellow-100";
      case "out of stock":
        return "text-red-600 bg-red-100";
      default:
        return "text-gray-600 bg-gray-100";
    }
  };

  // Archive functionality
  const openDeleteModal = (item) => {
    setSelectedItem(item);
    setShowDeleteModal(true);
  };

  const closeDeleteModal = () => {
    setShowDeleteModal(false);
    setSelectedItem(null);
  };

  const handleDeleteItem = async () => {
    if (!selectedItem) return;
    
    setArchiveLoading(true);
    try {
      const response = await handleApiCall("delete_product", {
        product_id: selectedItem.product_id,
        reason: "Archived from convenience store inventory",
        archived_by: "admin"
      });
      
      if (response.success) {
        toast.success("Product archived successfully");
        closeDeleteModal();
        loadProducts(); // Reload products
      } else {
        toast.error(response.message || "Failed to archive product");
      }
    } catch (error) {
      console.error("Error archiving product:", error);
      toast.error("Failed to archive product");
    } finally {
      setArchiveLoading(false);
    }
  };

  // Load batch data for a product from tbl_batch_transfer_details
  const loadBatchData = async (productId) => {
    setLoadingBatch(true);
    console.log("🚀 Starting loadBatchData for product:", productId);
    console.log("🚀 Convenience location ID:", convenienceLocationId);
    
    try {
      console.log("🔍 Loading batch transfer details for product:", productId);
      console.log("🔍 Convenience location ID:", convenienceLocationId);
      
      // Get batch transfer details from tbl_batch_transfer_details
      const batchResponse = await handleApiCall("get_convenience_batch_details", {
        location_id: convenienceLocationId,
        product_id: productId
      });
      
      console.log("🔍 Raw Batch Transfer Response:", batchResponse);
      console.log("🔍 Response Success:", batchResponse?.success);
      console.log("🔍 Response Data:", batchResponse?.data);
      
      if (batchResponse.success && batchResponse.data) {
        // Handle both data structures - direct array or object with batch_details
        const batchDetails = Array.isArray(batchResponse.data) 
          ? batchResponse.data 
          : batchResponse.data.batch_details || [];
        const summary = batchResponse.data.summary || {};
        
        console.log("🔍 Batch Transfer Data:", batchResponse.data);
        console.log("🔍 Batch Details:", batchDetails);
        console.log("🔍 Summary:", summary);
        
        if (batchDetails.length > 0) {
          console.log("✅ Found batch transfer details from tbl_batch_transfer_details");
          console.log("✅ Setting batchData to:", batchDetails);
          // Update the modal with summary data
          updateBatchSummary(summary);
          // Set individual batch data
          setBatchData(batchDetails);
        } else {
          console.log("⚠️ No batch transfer details found, trying FIFO fallback");
          console.log("⚠️ batchDetails was empty:", batchDetails);
          // Fallback to FIFO stock if no batch details found
          const fifoResponse = await handleApiCall("get_fifo_stock", {
            product_id: productId
          });
          
          console.log("🔍 FIFO Response:", fifoResponse);
          
          if (fifoResponse.success && Array.isArray(fifoResponse.data)) {
            console.log("✅ Using FIFO data as fallback");
            setBatchData(fifoResponse.data);
          } else {
            console.log("❌ No FIFO data either");
            setBatchData([]);
          }
        }
      } else {
        console.log("❌ Batch transfer API failed, trying FIFO fallback");
        // Fallback to FIFO stock if no batch details found
        const fifoResponse = await handleApiCall("get_fifo_stock", {
          product_id: productId
        });
        
        console.log("🔍 FIFO Response:", fifoResponse);
        
        if (fifoResponse.success && Array.isArray(fifoResponse.data)) {
          console.log("✅ Using FIFO data as fallback");
          setBatchData(fifoResponse.data);
        } else {
          console.log("❌ No FIFO data either");
          setBatchData([]);
        }
      }
    } catch (error) {
      console.error("❌ Error loading batch transfer data:", error);
      console.error("❌ Error details:", error.message);
      setBatchData([]);
    } finally {
      console.log("🏁 Finished loadBatchData, setting loadingBatch to false");
      setLoadingBatch(false);
    }
  };

  // Open batch modal
  const openBatchModal = (product) => {
    setSelectedProductForBatch(product);
    setShowBatchModal(true);
    loadBatchData(product.product_id);
  };

  // Close batch modal
  const closeBatchModal = () => {
    setShowBatchModal(false);
    setSelectedProductForBatch(null);
    setBatchData([]);
  };

  // Transfer history functions
  const openQuantityHistoryModal = (product) => {
    setSelectedProductForHistory(product);
    setShowQuantityHistoryModal(true);
    setShowCurrentFifoData(true);
    refreshProductData(product.product_id);
    loadTransferHistory(product.product_id);
  };

  const closeQuantityHistoryModal = () => {
    setShowQuantityHistoryModal(false);
    setSelectedProductForHistory(null);
    setQuantityHistoryData([]);
    setFifoStockData([]);
  };

  const refreshProductData = async (productId) => {
    try {
      const response = await handleApiCall("get_fifo_stock", { product_id: productId });
      if (response.success && Array.isArray(response.data)) {
        setFifoStockData(response.data);
      }
    } catch (error) {
      console.error("Error refreshing product data:", error);
    }
  };

  const loadTransferHistory = async (productId) => {
    setLoadingBatch(true);
    try {
      console.log("Loading transfer history for product ID:", productId);
      const response = await handleApiCall("get_convenience_batch_details", {
        location_id: convenienceLocationId,
        product_id: productId
      });

      console.log("Transfer history response:", response);
      
      if (response.success && response.data) {
        console.log("Transfer data received:", response.data);
        const batchDetails = response.data.batch_details || [];
        const summary = response.data.summary || {};
        
        console.log("Batch details extracted:", batchDetails);
        console.log("Summary extracted:", summary);
        
        // Update the modal with summary data
        updateBatchSummary(summary);
        
        displayBatchDetails(batchDetails);
      } else {
        console.error("Error loading transfer history:", response.message);
        const tableDiv = document.getElementById('transferHistoryTable');
        if (tableDiv) {
          tableDiv.innerHTML = '<div class="text-center py-4 text-red-500">Error loading transfer history: ' + response.message + '</div>';
        }
      }
    } catch (error) {
      console.error("Error loading transfer history:", error);
      const tableDiv = document.getElementById('transferHistoryTable');
      if (tableDiv) {
        tableDiv.innerHTML = '<div class="text-center py-4 text-red-500">Error: ' + error.message + '</div>';
      }
    } finally {
      setLoadingBatch(false);
    }
  };

  const updateBatchSummary = (summary) => {
    // This function is now handled by React state instead of direct DOM manipulation
    console.log("Updating batch summary:", summary);
    // Summary data will be displayed through React state
  };

  const displayTransferHistory = (transferLogs) => {
    // This function is now handled by React state instead of direct DOM manipulation
    console.log("Displaying transfer history:", transferLogs);
    setQuantityHistoryData(transferLogs || []);
  };

  const displayBatchDetails = (batchDetails) => {
    // This function is now handled by React state instead of direct DOM manipulation
    console.log("Displaying batch details:", batchDetails);
    setBatchData(batchDetails || []);
    setQuantityHistoryData(batchDetails || []);
  };

  // Batch transfer functions
  const loadBatchTransfers = async () => {
    if (!convenienceLocationId) return;
    
    setLoadingBatchTransfers(true);
    try {
      console.log("🔄 Loading batch transfers for convenience store...");
      
      const response = await handleApiCall("get_batch_transfers_by_location", {
        location_id: convenienceLocationId
      });
      
      console.log("📦 Batch Transfers Response:", response);
      
      if (response.success && response.data) {
        console.log("✅ Loaded batch transfers:", response.data.batch_transfers.length);
        setBatchTransferData(response.data.batch_transfers);
        setBatchTransferSummary(response.data.summary);
      } else {
        console.warn("⚠️ Failed to load batch transfers:", response.message);
        setBatchTransferData([]);
        setBatchTransferSummary({});
      }
    } catch (error) {
      console.error("❌ Error loading batch transfers:", error);
      setBatchTransferData([]);
      setBatchTransferSummary({});
    } finally {
      setLoadingBatchTransfers(false);
    }
  };

  const openBatchTransferModal = () => {
    setShowBatchTransferModal(true);
    loadBatchTransfers();
  };

  const closeBatchTransferModal = () => {
    setShowBatchTransferModal(false);
    setBatchTransferData([]);
    setBatchTransferSummary({});
  };


  const categories = [...new Set(products.filter(p => p && p.category).map(p => {
    // Handle both string and object category formats
    if (typeof p.category === 'string') {
      return p.category;
    } else if (p.category && typeof p.category === 'object' && p.category.category_name) {
      return p.category.category_name;
    }
    return null;
  }).filter(Boolean))];

  // --- Dashboard Statistics Calculation ---
  // Calculate total store value using total_quantity (aggregated by product)
  const totalStoreValue = products.reduce(
    (sum, p) => sum + (Number(p.first_batch_srp || p.srp || 0) * Number(p.total_quantity || p.quantity || 0)),
    0
  );
  // For demo, use static percentage changes
  const percentChangeProducts = 3; // +3% from last month
  const percentChangeValue = 1; // +1% from last month
  // Low stock count - check based on total_quantity
  const lowStockCount = products.filter(p => {
    const totalQty = parseInt(p.total_quantity || p.quantity || 0);
    return totalQty > 0 && totalQty <= (settings.lowStockThreshold || 5);
  }).length;

  // --- Pagination State ---
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 20; // Increased from 10 to show more products
  
  // Filter products by search term and category
  const filteredProducts = products.filter(product => {
    // Filter by search term
    if (searchTerm) {
      const searchLower = searchTerm.toLowerCase();
      const matchesSearch = 
        product.product_name?.toLowerCase().includes(searchLower) ||
        product.barcode?.toLowerCase().includes(searchLower) ||
        product.category?.toLowerCase().includes(searchLower) ||
        product.brand?.toLowerCase().includes(searchLower) ||
        product.supplier_name?.toLowerCase().includes(searchLower);
      if (!matchesSearch) return false;
    }
    
    // Filter by category
    if (selectedCategory !== 'all') {
      const categoryMatch = typeof product.category === 'string' 
        ? product.category === selectedCategory 
        : product.category?.category_name === selectedCategory;
      if (!categoryMatch) return false;
    }
    
    // Filter by product type
    if (selectedProductType !== 'all') {
      if (product.product_type !== selectedProductType) return false;
    }
    
    return true;
  });
  
  const paginatedProducts = filteredProducts.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage
  );
  const totalPages = Math.ceil(filteredProducts.length / itemsPerPage);

  return (
    <div className="min-h-screen w-full" style={{ backgroundColor: theme.bg.primary }}>
      <NotificationSystem 
        products={products} 
        onAlertCountChange={setAlertCount}
      />
      {/* Header */}
      <div className="w-full p-6 pb-4">
        <div className="flex items-center text-sm mb-2" style={{ color: theme.text.secondary }}>
          <span>Inventory Management</span>
          <div className="mx-2">{">"}</div>
          <span style={{ color: theme.colors.accent }}>Convenience Store</span>
        </div>
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold" style={{ color: theme.text.primary }}>Convenience Store Inventory</h1>
            <p style={{ color: theme.text.secondary }}>Manage convenience store products and transfers</p>
          </div>
          
          {/* Action Buttons */}
          <div className="flex items-center gap-3">
            {/* Batch Transfer Button */}
            <button
              onClick={openBatchTransferModal}
              className="flex items-center gap-2 px-4 py-2 rounded-lg border transition-colors"
              style={{ 
                borderColor: theme.colors.accent,
                backgroundColor: theme.colors.accent + '10',
                color: theme.colors.accent
              }}
              onMouseEnter={(e) => {
                e.target.style.backgroundColor = theme.colors.accent + '20';
              }}
              onMouseLeave={(e) => {
                e.target.style.backgroundColor = theme.colors.accent + '10';
              }}
            >
              <Package className="h-4 w-4" />
              <span className="text-sm font-medium">Batch Transfers</span>
            </button>


            {/* Notification Bell */}
            <div className="relative notification-dropdown">
              <button
                onClick={() => setShowNotifications(!showNotifications)}
                className="relative p-2 rounded-full hover:bg-opacity-10 hover:bg-gray-500 transition-colors"
                title="View Notifications"
              >
                {alertCount > 0 ? (
                  <BellRing className="h-6 w-6" style={{ color: theme.colors.warning }} />
                ) : (
                  <Bell className="h-6 w-6" style={{ color: theme.text.secondary }} />
                )}
                
                {/* Notification Badge */}
                {alertCount > 0 && (
                  <span className="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center animate-pulse">
                    {Math.min(alertCount, 99)}
                  </span>
                )}
              </button>

            {/* Notification Dropdown */}
            {showNotifications && (
              <div className="absolute right-0 mt-2 w-96 rounded-lg shadow-2xl border z-50 max-h-96 overflow-y-auto" 
                   style={{ backgroundColor: theme.bg.card, borderColor: theme.border.default, boxShadow: `0 25px 50px ${theme.shadow}` }}>
                <div className="p-4 border-b" style={{ borderColor: theme.border.default }}>
                  <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>Notifications</h3>
                  <p className="text-sm" style={{ color: theme.text.secondary }}>
                    {notifications.expiring.length + notifications.lowStock.length + notifications.outOfStock.length} alerts
                  </p>
                </div>

                <div className="max-h-80 overflow-y-auto">
                  {/* Expiring Products */}
                  {notifications.expiring.length > 0 && (
                    <div className="p-4 border-b" style={{ borderColor: theme.border.light }}>
                      <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.warning }}>
                        <Clock className="h-4 w-4 mr-2" />
                        Expiring Soon ({notifications.expiring.length})
                      </h4>
                      {notifications.expiring && Array.isArray(notifications.expiring) ? notifications.expiring.slice(0, 5).filter(product => product && typeof product === 'object').map((product, index) => (
                        <div key={index} className="flex justify-between items-center py-1">
                          <span className="text-sm" style={{ color: theme.text.primary }}>{product.product_name}</span>
                          <span className="text-xs px-2 py-1 rounded" style={{ 
                            backgroundColor: theme.colors.warning + '20', 
                            color: theme.colors.warning 
                          }}>
                            {product.expiration && product.expiration !== 'null' ? Math.ceil((new Date(product.expiration) - new Date()) / (1000 * 60 * 60 * 24)) : 0} days (Alert: {settings.expiryWarningDays}d)
                          </span>
                        </div>
                      )) : null}
                      {notifications.expiring && notifications.expiring.length > 5 && (
                        <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                          +{notifications.expiring.length - 5} more...
                        </p>
                      )}
                    </div>
                  )}

                  {/* Low Stock Products */}
                  {notifications.lowStock.length > 0 && (
                    <div className="p-4 border-b" style={{ borderColor: theme.border.light }}>
                      <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.warning }}>
                        <AlertCircle className="h-4 w-4 mr-2" />
                        Low Stock ({notifications.lowStock.length})
                      </h4>
                      {notifications.lowStock && Array.isArray(notifications.lowStock) ? notifications.lowStock.slice(0, 5).filter(product => product && typeof product === 'object').map((product, index) => (
                        <div key={index} className="flex justify-between items-center py-1">
                          <span className="text-sm" style={{ color: theme.text.primary }}>{product.product_name}</span>
                          <span className="text-xs px-2 py-1 rounded" style={{ 
                            backgroundColor: theme.colors.warning + '20', 
                            color: theme.colors.warning 
                          }}>
                            {product.quantity} left
                          </span>
                        </div>
                      )) : null}
                      {notifications.lowStock && notifications.lowStock.length > 5 && (
                        <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                          +{notifications.lowStock.length - 5} more...
                        </p>
                      )}
                    </div>
                  )}

                  {/* Out of Stock Products */}
                  {notifications.outOfStock.length > 0 && (
                    <div className="p-4">
                      <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.danger }}>
                        <Package className="h-4 w-4 mr-2" />
                        Out of Stock ({notifications.outOfStock.length})
                      </h4>
                      {notifications.outOfStock && Array.isArray(notifications.outOfStock) ? notifications.outOfStock.slice(0, 5).filter(product => product && typeof product === 'object').map((product, index) => (
                        <div key={index} className="flex justify-between items-center py-1">
                          <span className="text-sm" style={{ color: theme.text.primary }}>{product.product_name}</span>
                          <span className="text-xs px-2 py-1 rounded" style={{ 
                            backgroundColor: theme.colors.danger + '20', 
                            color: theme.colors.danger 
                          }}>
                            0 stock
                          </span>
                        </div>
                      )) : null}
                      {notifications.outOfStock && notifications.outOfStock.length > 5 && (
                        <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                          +{notifications.outOfStock.length - 5} more...
                        </p>
                      )}
                    </div>
                  )}

                  {/* No Notifications */}
                  {(notifications.expiring.length + notifications.lowStock.length + notifications.outOfStock.length) === 0 && (
                    <div className="p-8 text-center">
                      <CheckCircle className="h-12 w-12 mx-auto mb-2" style={{ color: theme.colors.success }} />
                      <p className="text-sm" style={{ color: theme.text.secondary }}>All products are in good condition!</p>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Dashboard Cards */}
      <div className="w-full px-6 pb-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
          {/* Store Products */}
          <div className="rounded-xl shadow-md p-4 sm:p-6 flex justify-between items-center min-h-[110px] w-full" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
            <div className="flex-1 min-w-0">
              <div className="text-xs font-medium mb-1" style={{ color: theme.text.muted }}>STORE PRODUCTS</div>
              <div className="text-2xl sm:text-3xl lg:text-4xl font-bold" style={{ color: theme.text.primary }}>{products.length}</div>
              <div className="text-xs mt-2" style={{ color: theme.text.secondary }}>+{percentChangeProducts}% from last month</div>
            </div>
            <div className="ml-4 flex-shrink-0">
              <Package className="h-8 w-8 sm:h-10 sm:w-10" style={{ color: theme.colors.accent }} />
            </div>
          </div>
          {/* Low Stock Items */}
          <div className="rounded-xl shadow-md p-4 sm:p-6 flex justify-between items-center min-h-[110px] w-full" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
            <div className="flex-1 min-w-0">
              <div className="text-xs font-medium mb-1" style={{ color: theme.text.muted }}>LOW STOCK ITEMS</div>
              <div className="text-2xl sm:text-3xl lg:text-4xl font-bold" style={{ color: theme.text.primary }}>{lowStockCount}</div>
              <div className="text-xs mt-2" style={{ color: theme.text.secondary }}>items below threshold</div>
            </div>
            <div className="ml-4 flex-shrink-0">
              <AlertCircle className="h-8 w-8 sm:h-10 sm:w-10" style={{ color: theme.colors.danger }} />
            </div>
          </div>
          {/* Store Value */}
          <div className="rounded-xl shadow-md p-4 sm:p-6 flex justify-between items-center min-h-[110px] w-full sm:col-span-2 lg:col-span-1" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
            <div className="flex-1 min-w-0">
              <div className="text-xs font-medium mb-1" style={{ color: theme.text.muted }}>STORE VALUE</div>
              <div className="text-lg sm:text-2xl lg:text-3xl xl:text-4xl font-bold break-words" style={{ color: theme.text.primary }}>
                ₱{totalStoreValue.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2})}
              </div>
              <div className="text-xs mt-2" style={{ color: theme.text.secondary }}>+{percentChangeValue}% from last month</div>
            </div>
            <div className="ml-4 flex-shrink-0">
              <Package className="h-8 w-8 sm:h-10 sm:w-10" style={{ color: theme.colors.warning }} />
            </div>
          </div>
        </div>
      </div>

      {/* Filters and Search */}
      <div className="w-full px-6 pb-4">
        <div className="rounded-3xl shadow-xl p-6 mb-6" style={{ backgroundColor: theme.bg.card, boxShadow: `0 25px 50px ${theme.shadow}` }}>
          <div className="flex flex-col md:flex-row gap-4">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4" style={{ color: theme.text.muted }} />
                <input
                  type="text"
                  placeholder="Search products..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="pl-10 pr-4 py-2 w-full border rounded-md focus:outline-none focus:ring-2"
                  style={{ 
                    borderColor: theme.border.default, 
                    backgroundColor: theme.bg.secondary,
                    color: theme.text.primary,
                    focusRingColor: theme.colors.accent
                  }}
                />
              </div>
            </div>
            <div className="w-full md:w-48">
              <select
                value={selectedCategory}
                onChange={(e) => setSelectedCategory(e.target.value)}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default, 
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              >
                <option value="all">All Categories</option>
                {categories.map((category) => (
                  <option key={category} value={typeof category === 'string' ? category : (category?.category_name || 'Unknown')}>
                    {typeof category === 'string' ? category : (category?.category_name || 'Unknown')}
                  </option>
                ))}
              </select>
            </div>
            <div className="w-full md:w-48">
              <select
                value={selectedProductType}
                onChange={(e) => setSelectedProductType(e.target.value)}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.bg.secondary, 
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              >
                <option value="all">All Types</option>
                <option value="Regular">Direct Stock Only</option>
                <option value="Transferred">Transferred Only</option>
              </select>
            </div>

          </div>
        </div>
      </div>

      {/* Inventory Table */}
      <div className="w-full px-6 pb-6">
        <div className="rounded-3xl shadow-xl w-full" style={{ backgroundColor: theme.bg.card, boxShadow: `0 25px 50px ${theme.shadow}` }}>
          <div className="px-6 py-4 border-b" style={{ borderColor: theme.border.default }}>
            <div className="flex justify-between items-center">
              <h3 className="text-xl font-semibold" style={{ color: theme.text.primary }}>Store Products</h3>
              <div className="flex items-center gap-4">
                <div className="text-sm" style={{ color: theme.text.secondary }}>
                  {filteredProducts.length} products found
                </div>
                {products.length > 0 && (
                  <div className="flex items-center gap-3 text-xs">
                    <div className="flex items-center gap-1">
                      <div className="w-3 h-3 rounded-full" style={{ backgroundColor: theme.colors.success }}></div>
                      <span style={{ color: theme.text.secondary }}>
                        {products.filter(p => p.product_type === 'Regular').length} Direct Stock
                      </span>
                    </div>
                    <div className="flex items-center gap-1">
                      <div className="w-3 h-3 rounded-full" style={{ backgroundColor: theme.colors.accent }}></div>
                      <span style={{ color: theme.text.secondary }}>
                        {products.filter(p => p.product_type === 'Transferred').length} Transferred
                      </span>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
          <div className="overflow-x-auto w-full">
            <table className="w-full min-w-max" style={{ color: theme.text.primary }}>
                          <thead className="border-b sticky top-0 z-10" style={{ backgroundColor: theme.bg.secondary, borderColor: theme.border.default }}>
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  PRODUCT NAME
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  BARCODE
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  CATEGORY
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  BRAND
                </th>
                <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  PRODUCT QTY
                </th>
                <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  SRP VALUE
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  SUPPLIER
                </th>
                <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  ACTIONS
                </th>
              </tr>
            </thead>
            <tbody className="divide-y" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.light }}>
              {loading ? (
                <tr>
                  <td colSpan={8} className="px-6 py-4 text-center" style={{ color: theme.text.secondary }}>
                    Loading products...
                  </td>
                </tr>
              ) : paginatedProducts.length > 0 ? (
                paginatedProducts.filter(product => product && typeof product === 'object').map((product, index) => {
                  // Check for alert conditions - use total_quantity for aggregated quantity by product
                  const quantity = parseInt(product.total_quantity || product.quantity || 0);
                  const isLowStock = settings.lowStockAlerts && isStockLow(quantity);
                  const isOutOfStock = quantity <= 0;
                  const isExpiringSoon = product.expiration && settings.expiryAlerts && isProductExpiringSoon(product.expiration);
                  const isExpired = product.expiration && isProductExpired(product.expiration);
                  
                  // Determine row styling based on alert conditions
                  let rowStyle = { backgroundColor: 'transparent' };
                  let rowClass = "hover:opacity-90 transition-all duration-200";
                  
                  if (isExpired) {
                    rowStyle.backgroundColor = theme.colors.danger + '10';
                    rowClass += " border-l-4 border-red-500";
                  } else if (isExpiringSoon) {
                    rowStyle.backgroundColor = theme.colors.warning + '10';
                    rowClass += " border-l-4 border-yellow-500";
                  } else if (isLowStock) {
                    rowStyle.backgroundColor = theme.colors.warning + '05';
                    rowClass += " border-l-4 border-orange-500";
                  } else if (isOutOfStock) {
                    rowStyle.backgroundColor = theme.colors.danger + '05';
                    rowClass += " border-l-4 border-red-400";
                  }
                  
                  return (
                  <tr key={`${product.product_id}-${index}`} className={rowClass} style={rowStyle}>
                    <td className="px-6 py-4">
                      <div className="text-sm font-medium" style={{ color: theme.text.primary }}>
                        {product.product_name}
                      </div>
                    </td>
                    <td className="px-6 py-4 text-sm font-mono" style={{ color: theme.text.primary }}>
                      {product.barcode}
                    </td>
                    <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>
                      <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.bg.secondary, color: theme.text.primary }}>
                        {product.category || product.category_name || 'Uncategorized'}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>
                      {product.brand || 'Generic'}
                    </td>
                    <td className="px-6 py-4 text-center">
                      <div className={`font-semibold ${quantity === 0 ? 'text-red-600' : quantity === 1 ? 'text-orange-600' : ''}`}>
                        {product.total_quantity || product.quantity || 0} pieces
                      </div>
                      {/* Show breakdown if multiple batches */}
                      {product.total_batches > 1 && (
                        <div className="text-xs text-gray-500 mt-1">
                          ({product.total_batches} batches)
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-4 text-center text-sm" style={{ color: theme.text.primary }}>
                      ₱{(() => {
                        const srpValue = Number.parseFloat(product.first_batch_srp || product.srp || 0);
                        return srpValue > 0 ? srpValue.toFixed(2) : '0.00';
                      })()}
                    </td>
                    <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>
                      {product.supplier_name || product.brand || "Unknown"}
                    </td>
                    <td className="px-6 py-4 text-center">
                      <div className="flex justify-center gap-2">
                        <button
                          onClick={() => openQuantityHistoryModal(product)}
                          className="px-3 py-1 text-xs font-medium rounded-lg border transition-colors hover:shadow-md"
                          style={{
                            borderColor: theme.colors.accent,
                            backgroundColor: theme.colors.accent + '10',
                            color: theme.colors.accent
                          }}
                          onMouseEnter={(e) => {
                            e.target.style.backgroundColor = theme.colors.accent + '20';
                          }}
                          onMouseLeave={(e) => {
                            e.target.style.backgroundColor = theme.colors.accent + '10';
                          }}
                          title="View Batch Details"
                        >
                          <History className="h-4 w-4 inline mr-1" />
                          Batch Details
                        </button>
                      </div>
                    </td>
                  </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-8 text-center">
                      <div className="flex flex-col items-center space-y-3">
                        <Package className="h-12 w-12 text-gray-300" />
                        <div className="text-gray-500">
                          <p className="text-lg font-medium">No products found</p>
                          <p className="text-sm">Products will appear here when transferred from warehouse</p>
                        </div>
                      </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex justify-center mt-4 pb-4">
              <div className="flex items-center space-x-2">
                <button
                  onClick={() => setCurrentPage(Math.max(1, currentPage - 1))}
                  disabled={currentPage === 1}
                  className="px-3 py-1 border border-gray-300 rounded disabled:opacity-50"
                >
                  Previous
                </button>
                <span className="px-3 py-1 text-sm">
                  Page {currentPage} of {totalPages}
                </span>
                <button
                  onClick={() => setCurrentPage(Math.min(totalPages, currentPage + 1))}
                  disabled={currentPage === totalPages}
                  className="px-3 py-1 border border-gray-300 rounded disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Delete Confirmation Modal */}
      {showDeleteModal && (
        <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
          <div className="bg-white/90 backdrop-blur-md rounded-xl shadow-2xl p-6 border border-gray-200/50 w-96">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Confirm Archive</h3>
            <p className="text-gray-700 mb-4">Are you sure you want to archive this product?</p>
            <div className="flex justify-end space-x-4">
              <button
                type="button"
                onClick={closeDeleteModal}
                className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleDeleteItem}
                className="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-md disabled:opacity-50"
              >
                {archiveLoading ? "Archiving..." : "Archive"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Batch Details Modal */}
      {showBatchModal && selectedProductForBatch && (() => {
        console.log("🎭 Rendering Batch Modal");
        console.log("🎭 batchData:", batchData);
        console.log("🎭 loadingBatch:", loadingBatch);
        console.log("🎭 selectedProductForBatch:", selectedProductForBatch);
        return true;
      })() && (
        <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50" style={{ zIndex: 9999 }}>
          <div className="rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto bg-white">
            {/* Header */}
            <div className="flex items-center justify-between p-6 border-b border-gray-200">
              <h3 className="text-2xl font-bold text-gray-900">
                📦 Batch Details - {selectedProductForBatch.product_name}
              </h3>
              <div className="flex items-center gap-2">
                <button 
                  onClick={closeBatchModal}
                  className="p-2 text-gray-500 hover:text-gray-700"
                >
                  <X className="h-6 w-6" />
                </button>
              </div>
            </div>

            {/* Summary Cards */}
            <div className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                {/* Product Info Card */}
                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-gray-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-gray-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">{selectedProductForBatch.product_name}</h4>
                      <p className="text-sm text-gray-600">Product ID: {selectedProductForBatch.product_id}</p>
                      <p className="text-lg font-bold text-gray-600">Transfer ID: TR-{selectedProductForBatch.transfer_id || 'N/A'}</p>
                    </div>
                  </div>
                </div>

                {/* Transfer Details Card */}
                <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-green-100 p-2 rounded-lg">
                      <CheckCircle className="h-6 w-6 text-green-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Transfer Details</h4>
                      <p className="text-sm text-green-600">Quantity: {selectedProductForBatch.total_quantity || selectedProductForBatch.quantity || 0} pieces</p>
                      <p className="text-sm text-green-600">From: {selectedProductForBatch.source_location || 'Warehouse'}</p>
                      <p className="text-sm text-green-600">To: Convenience Store</p>
                    </div>
                  </div>
                </div>

                {/* Date Info Card */}
                <div className="bg-purple-50 border border-purple-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-purple-100 p-2 rounded-lg">
                      <Clock className="h-6 w-6 text-purple-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Transfer Date</h4>
                      <p className="text-sm text-purple-600">
                        {selectedProductForBatch.transfer_date && selectedProductForBatch.transfer_date !== 'null' ? new Date(selectedProductForBatch.transfer_date).toLocaleDateString() : 'Not Set'}
                      </p>
                      <p className="text-sm text-purple-600">
                        {selectedProductForBatch.transfer_date && selectedProductForBatch.transfer_date !== 'null' ? new Date(selectedProductForBatch.transfer_date).toLocaleTimeString() : 'Not Set'}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Batch Info Card */}
                <div className="bg-orange-50 border border-orange-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-orange-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-orange-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Batch Information</h4>
                      <p className="text-sm text-orange-600">Batches Used: {batchData.length}</p>
                      <p className="text-sm text-orange-600">FIFO Order: Active</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Batch Details Table */}
              <div className="border rounded-lg bg-white border-gray-200">
                <div className="px-6 py-4 border-b border-gray-200">
                  <h4 className="text-lg font-semibold text-gray-900">Batch Consumption Details</h4>
                  <p className="text-sm text-gray-600">Showing which batches were consumed for this transfer</p>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">FIFO Order</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Batch Reference</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Transferred QTY</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">SRP</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Expiry Date</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {loadingBatch ? (
                        <tr>
                          <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                            <div className="flex flex-col items-center space-y-2">
                              <Package className="h-8 w-8 text-gray-400" />
                              <p className="text-lg font-medium text-gray-900">Loading batch details...</p>
                              <p className="text-sm text-gray-600">Please wait while we fetch the batch information</p>
                            </div>
                          </td>
                        </tr>
                      ) : batchData.length > 0 ? (
                        batchData.filter(batch => batch && typeof batch === 'object' && (batch.batch_quantity || batch.quantity || 0) > 0).map((batch, index) => {
                          const expiryDate = batch.expiration_date && batch.expiration_date !== 'null' ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A';
                          const quantityUsed = batch.batch_quantity || batch.quantity || 0;
                          const srp = batch.srp || 0;
                          const batchReference = batch.batch_reference || `BR-${batch.batch_id || index + 1}`;
                          const isConsumed = quantityUsed > 0;
                          
                          return (
                            <tr key={batch.id || batch.batch_transfer_id || index} className="hover:bg-gray-50">
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                {index + 1}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                  {batchReference}
                                </span>
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                  {quantityUsed} pieces
                                </span>
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                ₱{Number.parseFloat(srp).toFixed(2)}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                {expiryDate}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center">
                                <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${isConsumed ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>
                                  {isConsumed ? 'Consumed' : 'Available'}
                                </span>
                              </td>
                            </tr>
                          );
                        })
                      ) : (
                        <tr>
                          <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                            <div className="flex flex-col items-center space-y-2">
                              <Package className="h-8 w-8 text-gray-400" />
                              <p className="text-lg font-medium text-gray-900">No detailed batch information available</p>
                              <p className="text-sm text-gray-600">This transfer may not have detailed batch tracking</p>
                            </div>
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Summary */}
              <div className="mt-6 p-4 rounded-lg bg-gray-50">
                <h4 className="font-semibold mb-2 text-gray-900">Transfer Summary</h4>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                  <div>
                    <span className="text-gray-600">Total Quantity:</span>
                    <span className="ml-2 font-semibold text-gray-900">{selectedProductForBatch?.total_quantity || selectedProductForBatch?.quantity || batchData.reduce((sum, batch) => sum + (batch.batch_quantity || 0), 0)} pieces</span>
                  </div>
                  <div>
                    <span className="text-gray-600">Batches Used:</span>
                    <span className="ml-2 font-semibold text-gray-900">{batchData.length}</span>
                  </div>
                  <div>
                    <span className="text-gray-600">Transfer Date:</span>
                    <span className="ml-2 font-semibold text-gray-900">
                      {selectedProductForBatch.transfer_date && selectedProductForBatch.transfer_date !== 'null' ? new Date(selectedProductForBatch.transfer_date).toLocaleDateString() : 'Not Set'}
                    </span>
                  </div>
                  <div>
                    <span className="text-gray-600">FIFO Order:</span>
                    <span className="ml-2 font-semibold text-green-600">✓ Active</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Batch Details Modal */}
      {showQuantityHistoryModal && selectedProductForHistory && (
        <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50" style={{ zIndex: 9999 }}>
          <div className="rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto bg-white">
            {/* Header */}
            <div className="flex items-center justify-between p-6 border-b border-gray-200">
              <h3 className="text-2xl font-bold text-gray-900">
                📦 Batch Details - {selectedProductForHistory.product_name}
              </h3>
              <div className="flex items-center gap-2">
                <button 
                  onClick={closeQuantityHistoryModal}
                  className="p-2 text-gray-500 hover:text-gray-700"
                >
                  <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>

            {/* Summary Cards */}
            <div className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                {/* Product Info Card */}
                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-gray-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-gray-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">{selectedProductForHistory.product_name}</h4>
                      <p className="text-sm text-gray-600">Product ID: {selectedProductForHistory.product_id}</p>
                      <p className="text-lg font-bold text-gray-600">Transfer ID: TR-{selectedProductForHistory.transfer_id || 'N/A'}</p>
                    </div>
                  </div>
                </div>

                {/* Transfer Details Card */}
                <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-green-100 p-2 rounded-lg">
                      <CheckCircle className="h-6 w-6 text-green-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Transfer Details</h4>
                      <p className="text-sm text-green-600">Quantity: {selectedProductForHistory.total_quantity || selectedProductForHistory.quantity || 0} pieces</p>
                      <p className="text-sm text-green-600">From: {selectedProductForHistory.source_location || 'Warehouse'}</p>
                      <p className="text-sm text-green-600">To: Convenience Store</p>
                    </div>
                  </div>
                </div>

                {/* Date Info Card */}
                <div className="bg-purple-50 border border-purple-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-purple-100 p-2 rounded-lg">
                      <Clock className="h-6 w-6 text-purple-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Transfer Date</h4>
                      <p className="text-sm text-purple-600" id="transfer-date">
                        {selectedProductForHistory.transfer_date && selectedProductForHistory.transfer_date !== 'null' ? new Date(selectedProductForHistory.transfer_date).toLocaleDateString() : 'Not Set'}
                      </p>
                      <p className="text-sm text-purple-600" id="transfer-time">
                        {selectedProductForHistory.transfer_date && selectedProductForHistory.transfer_date !== 'null' ? new Date(selectedProductForHistory.transfer_date).toLocaleTimeString() : 'Not Set'}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Batch Info Card */}
                <div className="bg-orange-50 border border-orange-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-orange-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-orange-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Batch Information</h4>
                      <p className="text-sm text-orange-600">Batches Used: {batchData.length}</p>
                      <p className="text-sm text-orange-600">FIFO Order: Active</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Batch Details Table */}
              <div className="border rounded-lg bg-white border-gray-200">
                <div className="px-6 py-4 border-b border-gray-200">
                  <h4 className="text-lg font-semibold text-gray-900">Batch Consumption Details</h4>
                  <p className="text-sm text-gray-600">Showing which batches were consumed for this transfer</p>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">FIFO Order</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Batch Reference</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Transferred QTY</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">SRP</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Expiry Date</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {loadingBatch ? (
                        <tr>
                          <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                            <div className="flex flex-col items-center space-y-2">
                              <Package className="h-8 w-8 text-gray-400" />
                              <p className="text-lg font-medium text-gray-900">Loading batch details...</p>
                              <p className="text-sm text-gray-600">Please wait while we fetch the batch information</p>
                            </div>
                          </td>
                        </tr>
                      ) : batchData.length > 0 ? (
                        batchData.filter(batch => batch && typeof batch === 'object' && (batch.batch_quantity || batch.quantity || 0) > 0).map((batch, index) => {
                          const expiryDate = batch.expiration_date && batch.expiration_date !== 'null' ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A';
                          const quantityUsed = batch.batch_quantity || batch.quantity || 0;
                          const srp = batch.srp || 0;
                          const batchReference = batch.batch_reference || `BR-${batch.batch_id || index + 1}`;
                          const isConsumed = quantityUsed > 0;
                          
                          return (
                            <tr key={batch.id || batch.batch_transfer_id || index} className="hover:bg-gray-50">
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                {index + 1}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                  {batchReference}
                                </span>
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                  {quantityUsed} pieces
                                </span>
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                ₱{Number.parseFloat(srp).toFixed(2)}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                {expiryDate}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center">
                                <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${isConsumed ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>
                                  {isConsumed ? 'Consumed' : 'Available'}
                                </span>
                              </td>
                            </tr>
                          );
                        })
                      ) : (
                        <tr>
                          <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                            <div className="flex flex-col items-center space-y-2">
                              <Package className="h-8 w-8 text-gray-400" />
                              <p className="text-lg font-medium text-gray-900">No detailed batch information available</p>
                              <p className="text-sm text-gray-600">This transfer may not have detailed batch tracking</p>
                            </div>
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Summary */}
              <div className="mt-6 p-4 rounded-lg bg-gray-50">
                <h4 className="font-semibold mb-2 text-gray-900">Transfer Summary</h4>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                  <div>
                    <span className="text-gray-600">Total Quantity:</span>
                    <span className="ml-2 font-semibold text-gray-900">{selectedProductForBatch?.total_quantity || selectedProductForBatch?.quantity || batchData.reduce((sum, batch) => sum + (batch.batch_quantity || 0), 0)} pieces</span>
                  </div>
                  <div>
                    <span className="text-gray-600">Batches Used:</span>
                    <span className="ml-2 font-semibold text-gray-900">{batchData.length}</span>
                  </div>
                  <div>
                    <span className="text-gray-600">Transfer Date:</span>
                    <span className="ml-2 font-semibold text-gray-900">
                      {selectedProductForHistory.transfer_date && selectedProductForHistory.transfer_date !== 'null' ? new Date(selectedProductForHistory.transfer_date).toLocaleDateString() : 'Not Set'}
                    </span>
                  </div>
                  <div>
                    <span className="text-gray-600">FIFO Order:</span>
                    <span className="ml-2 font-semibold text-green-600">✓ Active</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Batch Transfer Modal */}
      {showBatchTransferModal && (
        <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50" style={{ zIndex: 9999 }}>
          <div className="rounded-xl shadow-2xl max-w-7xl w-full mx-4 max-h-[90vh] overflow-y-auto bg-white">
            {/* Header */}
            <div className="flex items-center justify-between p-6 border-b border-gray-200">
              <div>
                <h3 className="text-2xl font-bold text-gray-900">📦 Batch Transfer History</h3>
                <p className="text-gray-600 mt-1">Convenience Store - All batch transfers and movements</p>
              </div>
              <div className="flex items-center gap-2">
                <button 
                  onClick={closeBatchTransferModal}
                  className="p-2 text-gray-500 hover:text-gray-700"
                >
                  <X className="h-6 w-6" />
                </button>
              </div>
            </div>

            {/* Summary Cards */}
            <div className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                {/* Total Transfers */}
                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-gray-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-gray-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Total Transfers</h4>
                      <p className="text-2xl font-bold text-gray-600">{batchTransferSummary.total_transfers || 0}</p>
                      <p className="text-xs text-gray-600 mt-1">Batch movements</p>
                    </div>
                  </div>
                </div>

                {/* Total Products */}
                <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-green-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-green-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Products</h4>
                      <p className="text-2xl font-bold text-green-600">{batchTransferSummary.total_products || 0}</p>
                      <p className="text-xs text-green-600 mt-1">Unique products</p>
                    </div>
                  </div>
                </div>

                {/* Total Quantity */}
                <div className="bg-purple-50 border border-purple-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-purple-100 p-2 rounded-lg">
                      <CheckCircle className="h-6 w-6 text-purple-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Total Quantity</h4>
                      <p className="text-2xl font-bold text-purple-600">{batchTransferSummary.total_quantity || 0}</p>
                      <p className="text-xs text-purple-600 mt-1">Units transferred</p>
                    </div>
                  </div>
                </div>

                {/* Total Value */}
                <div className="bg-orange-50 border border-orange-200 rounded-lg p-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-orange-100 p-2 rounded-lg">
                      <Package className="h-6 w-6 text-orange-600" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900">Total Value</h4>
                      <p className="text-2xl font-bold text-orange-600">₱{(batchTransferSummary.total_value || 0).toLocaleString()}</p>
                      <p className="text-xs text-orange-600 mt-1">Transfer value</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Batch Transfer Table */}
              <div className="border rounded-lg bg-white border-gray-200">
                <div className="px-6 py-4 border-b border-gray-200">
                  <h4 className="text-lg font-semibold text-gray-900">Batch Transfer Details</h4>
                  <p className="text-sm text-gray-600">Complete history of all batch transfers to convenience store</p>
                </div>
                
                {loadingBatchTransfers ? (
                  <div className="p-8 text-center">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-600 mx-auto mb-4"></div>
                    <p className="text-gray-500">Loading batch transfers...</p>
                  </div>
                ) : batchTransferData.length > 0 ? (
                  <div className="overflow-x-auto">
                    <table className="w-full">
                      <thead className="bg-gray-50">
                        <tr>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Batch Reference</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">SRP</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry Date</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Transfer Date</th>
                          <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Source</th>
                        </tr>
                      </thead>
                      <tbody className="bg-white divide-y divide-gray-200">
                        {batchTransferData.map((transfer, index) => {
                          const expiryDate = transfer.expiration_date && transfer.expiration_date !== 'null' 
                            ? new Date(transfer.expiration_date).toLocaleDateString() 
                            : 'N/A';
                          const transferDate = transfer.transfer_date && transfer.transfer_date !== 'null'
                            ? new Date(transfer.transfer_date).toLocaleDateString()
                            : 'N/A';
                          
                          return (
                            <tr key={transfer.batch_transfer_id} className="hover:bg-gray-50">
                              <td className="px-6 py-4">
                                <div>
                                  <div className="text-sm font-medium text-gray-900">{transfer.product_name}</div>
                                  <div className="text-sm text-gray-500">{transfer.barcode}</div>
                                  <div className="text-xs text-gray-400">{transfer.brand} - {transfer.category}</div>
                                </div>
                              </td>
                              <td className="px-6 py-4 text-center">
                                <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                  {transfer.batch_reference}
                                </span>
                              </td>
                              <td className="px-6 py-4 text-center">
                                <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                  {transfer.batch_quantity} pieces
                                </span>
                              </td>
                              <td className="px-6 py-4 text-center text-sm font-medium text-gray-900">
                                ₱{Number.parseFloat(transfer.batch_srp || 0).toFixed(2)}
                              </td>
                              <td className="px-6 py-4 text-center text-sm text-gray-500">
                                {expiryDate}
                              </td>
                              <td className="px-6 py-4 text-center">
                                <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                  transfer.status === 'Available' ? 'bg-green-100 text-green-800' :
                                  transfer.status === 'Consumed' ? 'bg-red-100 text-red-800' :
                                  transfer.status === 'Expired' ? 'bg-gray-100 text-gray-800' :
                                  'bg-yellow-100 text-yellow-800'
                                }`}>
                                  {transfer.status}
                                </span>
                              </td>
                              <td className="px-6 py-4 text-center text-sm text-gray-500">
                                {transferDate}
                              </td>
                              <td className="px-6 py-4 text-center">
                                <div className="text-xs text-gray-600">
                                  <div className="font-semibold text-gray-600">{transfer.source_location_name || 'Warehouse'}</div>
                                  <div>By: {transfer.employee_name || 'System'}</div>
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <div className="p-8 text-center text-gray-500">
                    <Package className="h-12 w-12 text-gray-300 mx-auto mb-4" />
                    <p className="text-lg font-medium">No batch transfers found</p>
                    <p className="text-sm">Batch transfers will appear here when products are transferred to convenience store</p>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
    </div>
  );
}


export default ConvenienceStore;
