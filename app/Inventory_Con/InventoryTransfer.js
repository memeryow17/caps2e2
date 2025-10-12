"use client";

import React, { useState, useEffect } from "react";
import ReactDOM from "react-dom";
import { logActivity } from "../../lib/utils";
import axios from "axios";
import { toast } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";
import { useAPI } from "../hooks/useAPI";
import {
  ChevronUp,
  ChevronDown,
  Plus,
  X,
  Search,
  Truck,
  Trash2,
  Package,
  CheckCircle,
  Clock,
  Package2,
  AlertTriangle,
  AlertCircle,
  Info,
  Eye,
} from "lucide-react";
import { useTheme } from './ThemeContext';

function InventoryTransfer() {
  const { isDarkMode, theme } = useTheme();
  const { api, loading: apiLoading, error: apiError } = useAPI();
  
  const [transfers, setTransfers] = useState([])
  const [transferLogs, setTransferLogs] = useState([])
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [currentStep, setCurrentStep] = useState(1)
  const [loading, setLoading] = useState(false)
  const [searchTerm, setSearchTerm] = useState("")
  const [dateFilter, setDateFilter] = useState("")
  const [selectedProducts, setSelectedProducts] = useState([])
  const [availableProducts, setAvailableProducts] = useState([])
  const [checkedProducts, setCheckedProducts] = useState([])
  const [showProductSelection, setShowProductSelection] = useState(false)
  const [productUnits, setProductUnits] = useState({}) // Store units for each product
  const [selectedUnits, setSelectedUnits] = useState({}) // Track selected unit per product
  const [productSearchTerm, setProductSearchTerm] = useState("")
  const [selectedCategory, setSelectedCategory] = useState("All Product Category")
  const [selectedSupplier, setSelectedSupplier] = useState("All Suppliers")
  const [locations, setLocations] = useState([])
  const [staff, setStaff] = useState([])
  const [expandedTransfer, setExpandedTransfer] = useState(null)
  const [selectedTransfers, setSelectedTransfers] = useState([])
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [transferToDelete, setTransferToDelete] = useState(null)
  const [loadingProducts, setLoadingProducts] = useState(false)

  // Transfer History States
  const [showQuantityHistoryModal, setShowQuantityHistoryModal] = useState(false)
  const [selectedProductForHistory, setSelectedProductForHistory] = useState(null)
  const [quantityHistoryData, setQuantityHistoryData] = useState([])
  const [showCurrentFifoData, setShowCurrentFifoData] = useState(false)
  const [fifoStockData, setFifoStockData] = useState([])
  
  // Batch Details Modal States
  const [showBatchDetailsModal, setShowBatchDetailsModal] = useState(false)
  const [selectedTransferForBatchDetails, setSelectedTransferForBatchDetails] = useState(null)

  // Expiration Alert States
  const [showExpirationAlert, setShowExpirationAlert] = useState(false)
  const [expiringProducts, setExpiringProducts] = useState([])
  const [expiryWarningDays, setExpiryWarningDays] = useState(10)

  // Step 1: Store Selection
  const [storeData, setStoreData] = useState({
    originalStore: "Warehouse", // Automatically set to Warehouse
    destinationStore: "",
    storesConfirmed: false,
  })

  // Step 2: Transfer Information
  const [transferInfo, setTransferInfo] = useState({
    transferredBy: "",
    receivedBy: "",
    deliveryDate: new Date().toISOString().split('T')[0], // Automatically set to today's date
  })

  // Current user data
  const [currentUser, setCurrentUser] = useState(null)

  // New state for update stock modal (similar to Warehouse.js)
  const [showUpdateStockModal, setShowUpdateStockModal] = useState(false);
  const [existingProduct, setExistingProduct] = useState(null);
  const [newStockQuantity, setNewStockQuantity] = useState("");
  const [newStockExpiration, setNewStockExpiration] = useState("");
  // Bulk mode fields for stock update
  const [newStockBoxes, setNewStockBoxes] = useState("");
  const [newStockStripsPerBox, setNewStockStripsPerBox] = useState("");
  const [newStockTabletsPerStrip, setNewStockTabletsPerStrip] = useState("");
  const [newStockPiecesPerPack, setNewStockPiecesPerPack] = useState("");
  // Configuration mode for stock update
  const [stockUpdateConfigMode, setStockUpdateConfigMode] = useState("bulk");
  const [editSrpEnabled, setEditSrpEnabled] = useState(false);
  const [newSrp, setNewSrp] = useState("");

  // API function
  async function handleApiCall(action, data = {}) {
    console.log("🚀 API Call Payload:", { action, ...data })

    try {
      const response = await api.callGenericAPI('transfer_api.php', action, data);
      console.log("✅ API Success Response:", response)
      return response;
    } catch (error) {
      console.error("❌ API Call Error:", error)
      return {
        success: false,
        message: error.message,
        error: "REQUEST_ERROR",
      }
    }
  }

  // Load product units from tbl_product_units
  const loadProductUnits = async (productId) => {
    try {
      const response = await api.callGenericAPI('backend.php', 'get_product_units', { product_id: productId });
      
      if (response.success && response.data) {
        console.log(`✅ Loaded units for product ${productId}:`, response.data);
        return response.data;
      } else {
        console.warn(`⚠️ No units found for product ${productId}`);
        return null;
      }
    } catch (error) {
      console.error(`❌ Error loading units for product ${productId}:`, error);
      return null;
    }
  }

  // Load units for multiple products
  const loadUnitsForProducts = async (products) => {
    const unitsData = {};
    const defaultUnits = {};
    
    for (const product of products) {
      const data = await loadProductUnits(product.product_id);
      if (data) {
        unitsData[product.product_id] = data.units;
        // Set default unit to the base unit or first unit
        const baseUnit = data.units.find(u => u.is_base_unit === 1) || data.units[0];
        if (baseUnit) {
          defaultUnits[product.product_id] = baseUnit.unit_name;
        }
      }
    }
    
    setProductUnits(unitsData);
    setSelectedUnits(defaultUnits);
  }

  // Expiration Check Functions
  const loadExpirySettings = () => {
    try {
      // Try to get expiry warning days from localStorage (set by Settings.js)
      const storedExpiryDays = localStorage.getItem("expiryWarningDays")
      if (storedExpiryDays) {
        setExpiryWarningDays(parseInt(storedExpiryDays))
      } else {
        // Default to 10 days if no setting found
        setExpiryWarningDays(10)
      }
    } catch (error) {
      console.warn("⚠️ Could not load expiry settings from localStorage:", error)
      // Fallback to 10 days
      setExpiryWarningDays(10)
    }
  }

  const checkProductExpiration = (product) => {
    if (!product.oldest_batch_expiration) return null
    
    try {
      const expDate = new Date(product.oldest_batch_expiration)
      const today = new Date()
      const diffTime = expDate.getTime() - today.getTime()
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
      
      return {
        daysUntilExpiry: diffDays,
        isExpired: diffDays < 0,
        isExpiringSoon: diffDays >= 0 && diffDays <= expiryWarningDays,
        isCritical: diffDays >= 0 && diffDays <= 7
      }
    } catch (error) {
      console.warn("⚠️ Error checking expiration for product:", product.product_name, error)
      return null
    }
  }

  const checkForExpiringProducts = (products) => {
    if (!products || products.length === 0) return []
    
    const expiring = []
    
    products.forEach(product => {
      const expiryInfo = checkProductExpiration(product)
      if (expiryInfo && (expiryInfo.isExpiringSoon || expiryInfo.isExpired)) {
        expiring.push({
          ...product,
          expiryInfo
        })
      }
    })
    
    // Sort by urgency: expired first, then by days until expiry
    expiring.sort((a, b) => {
      if (a.expiryInfo.isExpired && !b.expiryInfo.isExpired) return -1
      if (!a.expiryInfo.isExpired && b.expiryInfo.isExpired) return 1
      return a.expiryInfo.daysUntilExpiry - b.expiryInfo.daysUntilExpiry
    })
    
    return expiring
  }

  const showExpirationAlertIfNeeded = (products) => {
    const expiring = checkForExpiringProducts(products)
    
    if (expiring.length > 0) {
      setExpiringProducts(expiring)
      setShowExpirationAlert(true)
      
      // Show toast notification
      const criticalCount = expiring.filter(p => p.expiryInfo.isCritical).length
      const expiredCount = expiring.filter(p => p.expiryInfo.isExpired).length
      
      if (expiredCount > 0) {
        toast.error(`🚨 ${expiredCount} product(s) have EXPIRED! Please check immediately.`)
      } else if (criticalCount > 0) {
        toast.warning(`⚠️ ${criticalCount} product(s) will expire within 7 days!`)
      } else {
        toast.info(`ℹ️ ${expiring.length} product(s) will expire within 10 days.`)
      }
    }
  }

  const getExpirationStatusColor = (expiryInfo) => {
    if (expiryInfo.isExpired) return 'bg-red-100 text-red-800'
    if (expiryInfo.isCritical) return 'bg-red-100 text-red-800'
    if (expiryInfo.isExpiringSoon) return 'bg-yellow-100 text-yellow-800'
    return 'bg-green-100 text-green-800'
  }

  const getExpirationStatusText = (expiryInfo) => {
    if (expiryInfo.isExpired) return 'EXPIRED'
    if (expiryInfo.isCritical) return 'CRITICAL'
    if (expiryInfo.isExpiringSoon) return 'EXPIRING SOON'
    return 'GOOD'
  }

  // Load data functions
  const loadTransfers = async () => {
    setLoading(true)
    try {
      const response = await handleApiCall("get_transfers_with_details")
      console.log("🔥 Transfers Loaded from API:", response)

      if (response.success && Array.isArray(response.data)) {
        console.log("✅ Number of transfers received:", response.data.length)
        console.log("📋 Transfer details:", response.data)
        
        // Process and enhance transfer data
        const processedTransfers = response.data.map(transfer => ({
          ...transfer,
          // Ensure products array exists and has proper structure
          products: transfer.products || [],
          // Calculate totals if not provided
          total_products: transfer.total_products || (transfer.products ? transfer.products.length : 0),
          total_value: transfer.total_value || (transfer.products ? 
            transfer.products.reduce((sum, product) => sum + (Number.parseFloat(product.srp || product.unit_price || 0) * Number.parseInt(product.qty || 0)), 0) : 0
          ),
          // Format status for display
          display_status: transfer.status === "approved" ? "Completed" : 
                         transfer.status === "pending" ? "Pending Review" : 
                         transfer.status === "rejected" ? "Rejected" : 
                         transfer.status || "Completed"
        }))
        
        console.log("🔄 Processed transfers:", processedTransfers)
        setTransfers(processedTransfers)
      } else {
        console.warn("⚠️ No transfers found or invalid format")
        console.log("🔍 Response structure:", response)
        setTransfers([])
      }
    } catch (error) {
      console.error("❌ Error loading transfers:", error)
      toast.error("Failed to load transfers")
      setTransfers([])
      try { await logActivity({ activityType: 'API_ERROR', description: `InventoryTransfer get_transfers_with_details failed: ${String(error && error.message ? error.message : error)}` }); } catch (_) {}
    } finally {
      setLoading(false)
    }
  }

  const loadTransferLogs = async () => {
    try {
      const response = await handleApiCall("get_transfer_log")
      console.log("📊 Transfer Logs Loaded from API:", response)

      if (response.success && Array.isArray(response.data)) {
        console.log("✅ Number of transfer logs received:", response.data.length)
        setTransferLogs(response.data)
      } else {
        console.warn("⚠️ No transfer logs found or invalid format")
        setTransferLogs([])
      }
    } catch (error) {
      console.error("❌ Error loading transfer logs:", error)
      setTransferLogs([])
      try { await logActivity({ activityType: 'API_ERROR', description: `InventoryTransfer get_transfer_log failed: ${String(error && error.message ? error.message : error)}` }); } catch (_) {}
    }
  }

  const loadAvailableProducts = async (sourceLocationId = null) => {
    setLoadingProducts(true)
    try {
      console.log("🔄 Loading warehouse products with FIFO oldest batch info...")
      
      // If no location ID provided, try to find warehouse location
      let locationId = sourceLocationId
      if (!locationId) {
        const warehouseLocation = locations.find(loc => 
          loc.location_name.toLowerCase().includes('warehouse') || loc.location_id === 2
        )
        if (warehouseLocation) {
          locationId = warehouseLocation.location_id
          console.log("🏭 Found warehouse location:", warehouseLocation.location_name, "(ID:", locationId, ")")
        } else {
          console.warn("⚠️ No warehouse location found, using default location ID 2")
          locationId = 2 // Default warehouse location ID from database
        }
      }
      
      // Load products with oldest batch info for transfer
      const response = await handleApiCall("get_products_oldest_batch_for_transfer", 
        { location_id: locationId }
      )
      
      if (response.success && Array.isArray(response.data)) {
        console.log("✅ Loaded products with oldest batch for transfer:", response.data.length)
        
        // Process the data to ensure proper field mapping
        const processedProducts = response.data.map(product => ({
          ...product,
          // Map the API response fields to expected frontend fields
          oldest_batch_reference: product.batch_reference || null,
          oldest_batch_quantity: product.oldest_batch_quantity || 0,
          oldest_batch_expiration: product.expiration_date || null,
          oldest_batch_entry_date: product.entry_date || null,
          oldest_batch_unit_cost: product.unit_cost || product.srp || 0,
          total_fifo_batches: product.total_batches || 1,
          total_quantity: product.total_quantity || product.oldest_batch_quantity || 0,
          available_for_transfer: product.oldest_batch_quantity || 0,
          // Ensure other required fields are present
          brand: product.brand || "-",
          supplier_name: product.supplier_name || "-"
        }))
        
        setAvailableProducts(processedProducts)
        console.log("📦 Available products state updated:", processedProducts.length, "products")
        console.log("Sample product:", processedProducts[0] || "No products")
        
        // Check for expiring products and show alert if needed
        showExpirationAlertIfNeeded(processedProducts)
        
        // Show success message to user
        if (processedProducts.length > 0) {
          toast.success(`✅ Loaded ${processedProducts.length} warehouse products successfully`)
        } else {
          toast.warning("⚠️ No warehouse products found with available stock")
        }
      } else {
        console.warn("⚠️ No products found or API error:", response.message)
        setAvailableProducts([])
        toast.error(`❌ Failed to load products: ${response.message || 'Unknown error'}`)
      }
    } catch (error) {
      console.error("❌ Error loading products:", error)
      toast.error("❌ Failed to load products from warehouse")
      setAvailableProducts([])
    } finally {
      setLoadingProducts(false)
    }
  }

  // Function to refresh available products with latest oldest batch data
  const refreshAvailableProducts = async (sourceLocationId = null) => {
    console.log("🔄 Refreshing available products with latest oldest batch data...")
    try {
      await loadAvailableProducts(sourceLocationId)
      console.log("✅ Available products refreshed successfully")
    } catch (error) {
      console.error("❌ Error refreshing available products:", error)
    }
  }

  // Load locations
  const loadLocations = async () => {
    try {
      const res = await handleApiCall("get_locations")
      console.log("📦 API Response from get_locations:", res)
      if (res.success && Array.isArray(res.data)) {
        setLocations(res.data)
        
        // Validate location mapping
        console.log("🔍 Location Mapping Validation:")
        console.log("📍 Total locations loaded:", res.data.length)
        res.data.forEach(loc => {
          console.log(`  - Location: ${loc.location_name} (ID: ${loc.location_id})`)
        })
        
        // Check for convenience store specifically (flexible matching)
        const convenienceStore = res.data.find(loc => 
          loc.location_name.toLowerCase().includes('convenience')
        )
        if (convenienceStore) {
          console.log("✅ Found Convenience Store:", convenienceStore.location_name, "(ID:", convenienceStore.location_id, ")")
        } else {
          console.warn("⚠️ No convenience store found in locations")
          console.warn("   Available locations:", res.data.map(loc => loc.location_name).join(', '))
          console.warn("   💡 Tip: Ensure you have a location containing 'convenience' in tbl_location table")
        }
        
        // Check for warehouse specifically
        const warehouse = res.data.find(loc => 
          loc.location_name.toLowerCase().includes('warehouse')
        )
        if (warehouse) {
          console.log("✅ Found Warehouse:", warehouse.location_name, "(ID:", warehouse.location_id, ")")
        } else {
          console.warn("⚠️ No warehouse found in locations")
          console.warn("   Available locations:", res.data.map(loc => loc.location_name).join(', '))
          console.warn("   💡 Tip: Ensure you have a location containing 'warehouse' in tbl_location table")
        }
      } else {
        console.warn("⚠️ No locations found or invalid response")
        setLocations([])
      }
    } catch (error) {
      console.error("❌ Failed to load locations:", error)
      setLocations([])
    }
  }

  // Load current user data
  const loadCurrentUser = async () => {
    try {
      console.log("🔍 Loading current user data...")
      
      // First, try to get user from sessionStorage (set during login)
      let userFromStorage = null;
      try {
        const storedUserData = sessionStorage.getItem('user_data');
        if (storedUserData) {
          userFromStorage = JSON.parse(storedUserData);
          console.log("📦 Found user data in sessionStorage:", userFromStorage);
        }
      } catch (storageErr) {
        console.warn("⚠️ Could not read from sessionStorage:", storageErr.message);
      }
      
      // Then, try to get from API
      const response = await handleApiCall("get_current_user")
      console.log("📋 Current user API response:", response)
      
      if (response.success && response.data) {
        // API returned user data successfully
        const userData = response.data;
        setCurrentUser(userData);
        
        // Auto-fill the transferred by field with current user's name
        const userName = userData.full_name || userData.fullName || userData.username || "Current User";
        setTransferInfo(prev => ({
          ...prev,
          transferredBy: userName
        }));
        
        console.log("✅ Current user loaded successfully from API:", userName);
        
        // Store in sessionStorage for future use
        try {
          sessionStorage.setItem('user_data', JSON.stringify(userData));
        } catch (storageErr) {
          console.warn("⚠️ Could not save to sessionStorage:", storageErr.message);
        }
      } else if (userFromStorage) {
        // API failed but we have data from sessionStorage
        console.log("ℹ️ Using user data from sessionStorage");
        setCurrentUser(userFromStorage);
        
        const userName = userFromStorage.full_name || userFromStorage.fullName || userFromStorage.username || "Current User";
        setTransferInfo(prev => ({
          ...prev,
          transferredBy: userName
        }));
        
        console.log("✅ Current user loaded from sessionStorage:", userName);
      } else {
        // No session found anywhere - use default
        console.warn("⚠️ No active session found - using default user");
        console.log("📊 API Debug Info:", response.debug);
        
        // Try to get a default user from staff list
        const staffResponse = await handleApiCall("get_inventory_staff");
        if (staffResponse.success && staffResponse.data && staffResponse.data.length > 0) {
          const firstStaff = staffResponse.data[0];
          console.log("👤 Using first staff member as default:", firstStaff.name);
          setTransferInfo(prev => ({
            ...prev,
            transferredBy: firstStaff.name
          }));
        } else {
          // Absolute fallback
          setTransferInfo(prev => ({
            ...prev,
            transferredBy: "Inventory Manager"
          }));
        }
        
        // Set current user to null to indicate no session
        setCurrentUser(null);
      }
    } catch (err) {
      console.error("❌ Error loading current user:", err);
      
      // Try to get user from sessionStorage as fallback
      try {
        const storedUserData = sessionStorage.getItem('user_data');
        if (storedUserData) {
          const userData = JSON.parse(storedUserData);
          setCurrentUser(userData);
          const userName = userData.full_name || userData.fullName || userData.username || "Current User";
          setTransferInfo(prev => ({
            ...prev,
            transferredBy: userName
          }));
          console.log("✅ Recovered user from sessionStorage:", userName);
          return;
        }
      } catch (storageErr) {
        console.warn("⚠️ Could not recover from sessionStorage");
      }
      
      // Final fallback
      console.warn("⚠️ Using default user due to error:", err.message);
      setTransferInfo(prev => ({
        ...prev,
        transferredBy: "Inventory Manager"
      }));
      setCurrentUser(null);
    }
  }

  // Load staff
  const loadStaff = async () => {
    try {
      const response = await handleApiCall("get_inventory_staff")
      if (response.success) {
        setStaff(response.data)
        console.log("👥 Staff loaded successfully:", response.data.length, "members")
        console.log("👥 Available staff:", response.data.map(emp => emp.name))
        } else {
          console.error("Failed to load inventory staff")
        }
      } catch (err) {
        console.error("Error loading staff:", err)
      }
    }

  // Function to calculate total products transferred
  const calculateTotalProductsTransferred = () => {
    let totalProducts = 0;
    
    // Calculate from transfer logs only (more accurate and avoids double counting)
    transferLogs.forEach(log => {
      totalProducts += Number.parseInt(log.quantity || 0);
    });
    
    return totalProducts;
  }

  // Function to get transfer statistics
  const getTransferStatistics = () => {
    const totalProducts = calculateTotalProductsTransferred();
    const totalTransfers = transfers.length;
    const totalLogs = transferLogs.length;
    
    return {
      totalProducts,
      totalTransfers,
      totalLogs,
      averageProductsPerTransfer: totalTransfers > 0 ? Math.round(totalProducts / totalTransfers) : 0
    };
  }

  // Function to track transfer records per session
  const [sessionTransfers, setSessionTransfers] = useState(0);
  const [sessionStartTime, setSessionStartTime] = useState(new Date());

  // Function to get session transfer statistics
  const getSessionTransferStats = () => {
    const currentTime = new Date();
    const sessionDuration = Math.round((currentTime - sessionStartTime) / 1000 / 60); // in minutes
    
    return {
      sessionTransfers,
      sessionDuration,
      transfersPerMinute: sessionDuration > 0 ? (sessionTransfers / sessionDuration).toFixed(2) : 0
    };
  };

  // Function to increment session transfer count
  const incrementSessionTransfers = () => {
    setSessionTransfers(prev => prev + 1);
  };

  // Function to reset session
  const resetSession = () => {
    setSessionTransfers(0);
    setSessionStartTime(new Date());
  };

  // Transfer history functions
  const openQuantityHistoryModal = (product) => {
    setSelectedProductForHistory(product);
    setShowQuantityHistoryModal(true);
    setShowCurrentFifoData(true);
    refreshProductData(product.product_id);
    // Transfer history will be loaded by useEffect when modal is rendered
  };

  const closeQuantityHistoryModal = () => {
    setShowQuantityHistoryModal(false);
    setSelectedProductForHistory(null);
    setQuantityHistoryData([]);
    setFifoStockData([]);
  };

  // Function to get detailed batch breakdown for a product
  const getDetailedBatchBreakdown = (product) => {
    const transferQty = product.transfer_quantity || 0;
    const oldestBatchQty = product.oldest_batch_quantity || 0;
    const totalAvailable = product.total_quantity || 0;
    
    if (transferQty <= 0) return [];
    
    const breakdown = [];
    
    if (transferQty <= oldestBatchQty) {
      // Only using oldest batch
      breakdown.push({
        batchNumber: 1,
        batchReference: product.oldest_batch_reference || 'N/A',
        quantity: transferQty,
        isOldest: true,
        remaining: oldestBatchQty - transferQty
      });
    } else {
      // Using multiple batches
      breakdown.push({
        batchNumber: 1,
        batchReference: product.oldest_batch_reference || 'N/A',
        quantity: oldestBatchQty,
        isOldest: true,
        remaining: 0
      });
      
      const remainingQty = transferQty - oldestBatchQty;
      breakdown.push({
        batchNumber: 2,
        batchReference: 'Next batches (FIFO)',
        quantity: remainingQty,
        isOldest: false,
        remaining: totalAvailable - transferQty
      });
    }
    
    return breakdown;
  };

  // Batch Details Modal Functions
  const openBatchDetailsModal = async (transfer) => {
    console.log("🔍 Opening batch details modal for transfer:", transfer);
    console.log("🔍 Current showBatchDetailsModal state:", showBatchDetailsModal);
    console.log("🔍 Current selectedTransferForBatchDetails state:", selectedTransferForBatchDetails);
    
    setSelectedTransferForBatchDetails(transfer);
    setShowBatchDetailsModal(true);
    
    // Load batch details for this transfer
    try {
      console.log("🔄 Loading batch details for transfer ID:", transfer.transfer_header_id);
      const batchResponse = await handleApiCall("get_transfer_batch_details", {
        transfer_id: transfer.transfer_header_id
      });
      
      if (batchResponse.success && Array.isArray(batchResponse.data)) {
        console.log("✅ Batch details loaded:", batchResponse.data);
        // Update the transfer object with batch details
        const updatedTransfer = {
          ...transfer,
          batch_details: batchResponse.data
        };
        setSelectedTransferForBatchDetails(updatedTransfer);
      } else {
        console.warn("⚠️ No batch details found for transfer:", transfer.transfer_header_id);
        // Set empty batch details
        const updatedTransfer = {
          ...transfer,
          batch_details: []
        };
        setSelectedTransferForBatchDetails(updatedTransfer);
      }
    } catch (error) {
      console.error("❌ Error loading batch details:", error);
      // Set empty batch details on error
      const updatedTransfer = {
        ...transfer,
        batch_details: []
      };
      setSelectedTransferForBatchDetails(updatedTransfer);
    }
    
    // Add a small delay to check state after setting
    setTimeout(() => {
      console.log("✅ Batch details modal state updated - showBatchDetailsModal:", showBatchDetailsModal);
      console.log("✅ Selected transfer set to:", transfer);
    }, 100);
  };

  const closeBatchDetailsModal = () => {
    setShowBatchDetailsModal(false);
    setSelectedTransferForBatchDetails(null);
  };

  const refreshProductData = async (productId) => {
    try {
      console.log("🔄 Refreshing FIFO data for product ID:", productId);
      const response = await handleApiCall("get_fifo_stock", { product_id: productId });
      console.log("📊 FIFO stock response:", response);
      
      if (response.success && Array.isArray(response.data)) {
        console.log("✅ FIFO data received:", response.data.length, "batches");
        setFifoStockData(response.data);
      } else {
        console.log("⚠️ No FIFO data found for product", productId);
        setFifoStockData([]);
      }
    } catch (error) {
      console.error("❌ Error refreshing product data:", error);
      setFifoStockData([]);
    }
  };

  const loadTransferHistory = async (productId) => {
    try {
      console.log("🔄 Loading transfer history for product ID:", productId);
      const response = await handleApiCall("get_transfer_logs", {
        product_id: productId,
        limit: 100
      });

      console.log("📊 Transfer history response:", response);

      if (response.success) {
        console.log("✅ Transfer data received:", response.data);
        if (response.data && response.data.length > 0) {
          console.log("📦 Found", response.data.length, "transfers for product", productId);
          displayTransferHistory(response.data);
        } else {
          console.log("⚠️ No transfers found for product", productId);
          displayTransferHistory([]);
        }
      } else {
        console.error("❌ Error loading transfer history:", response.message);
        const tableDiv = document.getElementById('transferHistoryTable');
        if (tableDiv) {
          tableDiv.innerHTML = '<div class="text-center py-4 text-red-500">Error loading transfer history: ' + response.message + '</div>';
        }
      }
    } catch (error) {
      console.error("❌ Error loading transfer history:", error);
      const tableDiv = document.getElementById('transferHistoryTable');
      if (tableDiv) {
        tableDiv.innerHTML = '<div class="text-center py-4 text-red-500">Error: ' + error.message + '</div>';
      }
    }
  };

  const displayTransferHistory = (transferLogs) => {
    const tableDiv = document.getElementById('transferHistoryTable');
    if (!tableDiv) {
      console.warn("Transfer history table element not found - modal may not be rendered yet");
      // Retry after a short delay
      setTimeout(() => {
        const retryTableDiv = document.getElementById('transferHistoryTable');
        if (retryTableDiv) {
          displayTransferHistory(transferLogs);
        }
      }, 200);
      return;
    }

    if (!transferLogs || transferLogs.length === 0) {
      tableDiv.innerHTML = `
        <div class="text-center py-8">
          <div class="text-4xl mb-4">📦</div>
          <p class="text-lg font-medium" style="color: ${theme.text.primary}">No transfer history found</p>
          <p class="text-sm" style="color: ${theme.text.secondary}">Transfer logs will appear here after transfers are created</p>
        </div>
      `;
      return;
    }

    let html = '';
    transferLogs.forEach((transfer, index) => {
      const transferDate = new Date(transfer.transfer_date).toLocaleDateString();
      const hasBatchDetails = transfer.batch_details && transfer.batch_details.length > 0;

      html += `
        <div class="mb-4 rounded-lg border" style="border-color: ${theme.border.default}; background-color: ${theme.bg.card}">
          <div class="p-4 border-b" style="border-color: ${theme.border.light}">
            <div class="flex justify-between items-center">
              <div>
                <h4 class="font-semibold" style="color: ${theme.text.primary}">Transfer #${transfer.transfer_id}</h4>
                <p class="text-sm" style="color: ${theme.text.secondary}">${transferDate} - ${transfer.product_name}</p>
              </div>
              <div class="text-right">
                <span class="inline-block px-3 py-1 text-sm font-medium rounded-full" style="background-color: ${theme.colors.info}20; color: ${theme.colors.info}">
                  ${transfer.quantity} units
                </span>
              </div>
            </div>
            <div class="flex gap-4 mt-2">
              <span class="inline-block px-2 py-1 text-xs font-medium rounded-full" style="background-color: ${theme.colors.danger}20; color: ${theme.colors.danger}">
                From: ${transfer.from_location}
              </span>
              <span class="inline-block px-2 py-1 text-xs font-medium rounded-full" style="background-color: ${theme.colors.success}20; color: ${theme.colors.success}">
                To: ${transfer.to_location}
              </span>
            </div>
          </div>

          ${hasBatchDetails ? `
            <div class="p-4">
              <h5 class="font-medium mb-3" style="color: ${theme.text.primary}">📦 Batch Details (${transfer.batch_details.length} batch${transfer.batch_details.length > 1 ? 'es' : ''})</h5>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr style="background-color: ${theme.bg.secondary}">
                      <th class="px-3 py-2 text-left font-medium" style="color: ${theme.text.secondary}">Batch #</th>
                      <th class="px-3 py-2 text-left font-medium" style="color: ${theme.text.secondary}">Reference</th>
                      <th class="px-3 py-2 text-center font-medium" style="color: ${theme.text.secondary}">Quantity</th>
                      <th class="px-3 py-2 text-center font-medium" style="color: ${theme.text.secondary}">Unit Cost</th>
                      <th class="px-3 py-2 text-center font-medium" style="color: ${theme.text.secondary}">Expiry Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${transfer.batch_details.map((batch, batchIndex) => `
                      <tr style="border-bottom: 1px solid ${theme.border.light}">
                        <td class="px-3 py-2 font-mono" style="color: ${theme.text.primary}">${batch.batch_number || batch.batch_id || `B-${transfer.transfer_id}-${batchIndex + 1}`}</td>
                        <td class="px-3 py-2 font-mono text-xs" style="color: ${theme.text.primary}">${batch.batch_reference}</td>
                        <td class="px-3 py-2 text-center font-medium" style="color: ${theme.colors.primary}">${batch.batch_quantity} units</td>
                        <td class="px-3 py-2 text-center" style="color: ${theme.text.primary}">₱${Number.parseFloat(batch.unit_cost || 0).toFixed(2)}</td>
                        <td class="px-3 py-2 text-center" style="color: ${theme.text.primary}">
                          ${batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A'}
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          ` : `
            <div class="p-4 text-center" style="color: ${theme.text.muted}">
              <p class="text-sm">No batch details available for this transfer</p>
            </div>
          `}
        </div>
      `;
    });

    tableDiv.innerHTML = html;
    try { tableDiv.style.color = theme.text.primary; } catch (e) {}
  };

  // Enhanced FIFO Stock Checking Functions
  const checkFifoStock = async (productId, locationId) => {
    try {
      const response = await handleApiCall("get_fifo_stock_status", {
        product_id: productId,
        location_id: locationId
      })
      
      if (response.success) {
        console.log("📊 FIFO Stock Status for Product", productId, ":", response)
        return response
      }
      return null
    } catch (error) {
      console.error("Error checking FIFO stock:", error)
      return null
    }
  }

  const checkFifoAvailability = async (productId, locationId, requestedQuantity) => {
    try {
      console.log("🔍 FIFO Availability Check Request:")
      console.log("  - Product ID:", productId)
      console.log("  - Location ID:", locationId)
      console.log("  - Requested Quantity:", requestedQuantity)
      
      const response = await handleApiCall("check_fifo_availability", {
        product_id: productId,
        location_id: locationId,
        requested_quantity: requestedQuantity
      })
      
      console.log("🔍 FIFO Availability Check Response:", response)
      
      if (response.success) {
        console.log("✅ FIFO Availability Check for Product", productId, ":", response)
        return response
      } else {
        console.error("❌ FIFO Availability Check failed:", response.message)
        return null
      }
    } catch (error) {
      console.error("Error checking FIFO availability:", error)
      return null
    }
  }

  const validateFifoStockBeforeTransfer = async (productsToTransfer, sourceLocationId) => {
    console.log("🔍 Validating FIFO stock for", productsToTransfer.length, "products...")
    
    let insufficientProducts = []
    
    for (const product of productsToTransfer) {
      const availability = await checkFifoAvailability(
        product.product_id,
        sourceLocationId,
        product.transfer_quantity
      )
      
      if (!availability || !availability.is_available) {
        const availableQty = availability ? availability.total_available : 0
        insufficientProducts.push({
          name: product.product_name,
          requested: product.transfer_quantity,
          available: availableQty
        })
        console.warn(`⚠️ Insufficient stock for ${product.product_name}. Available: ${availableQty}, Requested: ${product.transfer_quantity}`)
        continue // Continue checking other products instead of throwing error
      }
      
      // Show which batches will be used and provide detailed information
      if (availability.next_batches && availability.next_batches.length > 0) {
        const oldestBatch = availability.next_batches[0]
        const oldestBatchQty = oldestBatch.available_quantity
        const totalAvailable = availability.total_available
        const requestedQty = product.transfer_quantity
        
        console.log(`📦 ${product.product_name} - FIFO Transfer Details:`)
        console.log(`   - Oldest batch: ${oldestBatch.batch_reference} (${oldestBatch.entry_date}) - ${oldestBatchQty} units`)
        console.log(`   - Total available: ${totalAvailable} units across ${availability.batches_count} batches`)
        console.log(`   - Requested: ${requestedQty} units`)
        
        if (requestedQty > oldestBatchQty) {
          console.log(`   - ⚠️ Requested quantity (${requestedQty}) exceeds oldest batch (${oldestBatchQty})`)
          console.log(`   - 🔄 System will automatically consume from ${availability.batches_count} batches in FIFO order`)
          
          // Show which batches will be consumed
          let remainingQty = requestedQty
          let batchIndex = 0
          for (const batch of availability.next_batches) {
            const qtyFromBatch = Math.min(remainingQty, batch.available_quantity)
            console.log(`   - Batch ${batchIndex + 1}: ${batch.batch_reference} - ${qtyFromBatch} units`)
            remainingQty -= qtyFromBatch
            batchIndex++
            if (remainingQty <= 0) break
          }
        } else {
          console.log(`   - ✅ Requested quantity (${requestedQty}) fits within oldest batch (${oldestBatchQty})`)
        }
      }
    }
    
    // If there are insufficient products, show a warning but don&apos;t block the transfer
    if (insufficientProducts.length > 0) {
      const productNames = insufficientProducts.map(p => `${p.name} (${p.requested} requested, ${p.available} available)`).join(', ')
      console.warn(`⚠️ Some products have insufficient stock: ${productNames}`)
      console.warn(`⚠️ Transfer will proceed but may fail for insufficient products`)
      
      // Show a toast warning to the user
      toast.warning(`⚠️ Some products have insufficient stock. Transfer may fail for: ${insufficientProducts.map(p => p.name).join(', ')}`)
    }
    
    console.log("✅ FIFO stock validation completed - transfer will proceed")
    return true
  }

  // Enhanced FIFO Stock Info Component with Automatic Batch Switching
  const FifoStockInfo = ({ product, sourceLocationId, showFullDetails = false, transferQuantity = 0 }) => {
    const [fifoStock, setFifoStock] = useState(null)
    const [loading, setLoading] = useState(false)

    const loadFifoStock = async () => {
      setLoading(true)
      try {
        const response = await checkFifoStock(product.product_id, sourceLocationId)
        setFifoStock(response)
      } catch (error) {
        console.error("Error loading FIFO stock:", error)
      } finally {
        setLoading(false)
      }
    }

    useEffect(() => {
      loadFifoStock()
    }, [product.product_id, sourceLocationId])

    if (loading) {
      return <div className="text-xs text-gray-500">Loading...</div>
    }

    if (!fifoStock || !fifoStock.fifo_batches?.length) {
      return (
        <div className="text-xs text-gray-500">
          No FIFO data available
        </div>
      )
    }

    const oldestBatch = fifoStock.fifo_batches[0]
    const batchCount = fifoStock.batches_count

    // Calculate which batches will be consumed for the transfer quantity
    const calculateBatchConsumption = () => {
      if (!transferQuantity || transferQuantity <= 0) return []

      let remainingQty = transferQuantity
      const consumption = []

      for (const batch of fifoStock.fifo_batches) {
        if (remainingQty <= 0) break

        const qtyFromBatch = Math.min(remainingQty, batch.available_quantity)
        consumption.push({
          batch_reference: batch.batch_reference,
          available_quantity: batch.available_quantity,
          quantity_taken: qtyFromBatch,
          will_be_depleted: qtyFromBatch >= batch.available_quantity
        })

        remainingQty -= qtyFromBatch
      }

      return consumption
    }

    const batchConsumption = calculateBatchConsumption()
    const willUseMultipleBatches = batchConsumption.length > 1

    return (
      <div className={`text-xs ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>
        <div className={`p-2 rounded border ${
          willUseMultipleBatches 
            ? (isDarkMode ? 'border-orange-500 bg-orange-900/20' : 'border-orange-300 bg-orange-50')
            : (isDarkMode ? 'border-blue-500 bg-blue-900/20' : 'border-blue-300 bg-blue-50')
        }`}>
          <div className="font-medium mb-1">
            📦 Total Available: {fifoStock.total_available} units across {batchCount} batches
          </div>
          
          {transferQuantity > 0 && (
            <div className="mt-2">
              <div className="font-medium mb-1">
                📋 Transfer Breakdown ({transferQuantity} units)
              </div>
              {batchConsumption.map((batch, index) => (
                <div key={index} className="text-xs">
                  • {batch.batch_reference}: {batch.quantity_taken} units
                  {batch.will_be_depleted && " (will be depleted)"}
                </div>
              ))}
            </div>
          )}

          {showFullDetails && (
            <div className="mt-2 space-y-1">
              <div className="font-medium">
                🕐 Oldest Batch: {oldestBatch.batch_reference} ({oldestBatch.entry_date}) - {oldestBatch.available_quantity} units
              </div>
              {fifoStock.fifo_batches.slice(1, 3).map((batch, index) => (
                <div key={index} className="text-xs">
                  #{index + 2}: {batch.batch_reference} ({batch.entry_date}) - {batch.available_quantity} units
                </div>
              ))}
            </div>
          )}

          <div className="mt-2 text-xs opacity-75">
            🔄 FIFO: {batchCount} batches, {fifoStock.total_available} total | Next: {oldestBatch.batch_reference} ({oldestBatch.entry_date})
          </div>

          {transferQuantity > 0 && willUseMultipleBatches && (
            <div className={`mt-2 p-1 rounded text-xs font-medium ${
              isDarkMode ? 'bg-orange-900/40 text-orange-200' : 'bg-orange-100 text-orange-800'
            }`}>
              ⚡ Auto-switching to multiple batches
            </div>
          )}
        </div>
      </div>
    )
  }

  useEffect(() => {
    loadExpirySettings() // Load expiry settings first
    loadTransfers()
    loadTransferLogs()
    loadLocations()
    loadStaff()
    loadCurrentUser()
    
    // Create transfer batch details table if it doesn't exist
    handleApiCall("create_transfer_batch_details_table").then(response => {
      console.log("📊 Transfer batch details table creation response:", response);
    })
  }, [])

  // Load warehouse products when locations are available
  useEffect(() => {
    if (locations.length > 0) {
      const warehouseLocation = locations.find(loc => 
        loc.location_name.toLowerCase().includes('warehouse') || loc.location_id === 2
      )
      if (warehouseLocation) {
        console.log("🏭 Auto-loading warehouse products on component mount...")
        console.log("📍 Warehouse location found:", warehouseLocation.location_name, "(ID:", warehouseLocation.location_id, ")")
        loadAvailableProducts(warehouseLocation.location_id)
      } else {
        console.warn("⚠️ No warehouse location found in locations array, attempting to load with default ID")
        // Fallback: try to load with default warehouse location ID
        loadAvailableProducts(2)
      }
    }
  }, [locations])

  // Auto-set valid employee when staff is loaded and current transferredBy is invalid
  useEffect(() => {
    if (staff.length > 0 && transferInfo.transferredBy === "Inventory Manager") {
      // Find the first available staff member
      const firstStaffMember = staff[0]
      if (firstStaffMember && firstStaffMember.name) {
        console.log("👤 Auto-setting transferredBy to first available staff member:", firstStaffMember.name)
        setTransferInfo(prev => ({
          ...prev,
          transferredBy: firstStaffMember.name
        }))
      }
    }
  }, [staff, transferInfo.transferredBy])

  // Load transfer history when modal is opened
  useEffect(() => {
    if (showQuantityHistoryModal && selectedProductForHistory) {
      // Small delay to ensure modal is rendered
      const timer = setTimeout(() => {
        loadTransferHistory(selectedProductForHistory.product_id);
      }, 100);
      
      return () => clearTimeout(timer);
    }
  }, [showQuantityHistoryModal, selectedProductForHistory])

  // Fixed transfer submission function
  const handleTransferSubmit = async () => {
    const productsToTransfer = selectedProducts.filter((p) => p.transfer_quantity > 0)

    if (productsToTransfer.length === 0) {
      toast.error("Please add at least one product with quantity > 0")
      return
    }

    if (!storeData.originalStore || !storeData.destinationStore) {
      toast.error("Please select both original and destination stores")
      return
    }
    
    if (!storeData.storesConfirmed) {
      toast.error("Please confirm the store selection before proceeding")
      return
    }

    if (!transferInfo.transferredBy) {
      toast.error("Please select who is transferring the products")
      return
    }

    // Debug logging
    console.log("🚀 Starting transfer submission...")
    console.log("Products to transfer:", productsToTransfer)
    console.log("Store data:", storeData)
    console.log("Transfer info:", transferInfo)

    // Enhanced validation for convenience store transfers
    const isConvenienceStoreTransfer = storeData.destinationStore.toLowerCase().includes('convenience');
    const convenience = locations.find(loc => loc.location_name.toLowerCase().includes('convenience'));
    
    if (isConvenienceStoreTransfer) {
      console.log("🏪 Special handling for Warehouse → Convenience Store transfer")
      
      // Check for insufficient quantities but don&apos;t block the transfer
      const insufficientProducts = productsToTransfer.filter(p => {
        const availableQty = p.oldest_batch_quantity || p.available_for_transfer || p.quantity;
        return p.transfer_quantity > availableQty;
      })
      if (insufficientProducts.length > 0) {
        const productNames = insufficientProducts.map(p => p.product_name).join(', ')
        console.warn(`⚠️ Some products exceed available quantity for convenience store: ${productNames}`)
        toast.warning(`⚠️ Some products exceed available quantity. Transfer may fail for: ${productNames}`)
        // Don't return - allow the transfer to proceed
      }
    }

    setLoading(true)
    try {
      // Find location IDs with case-insensitive comparison
      console.log("🔍 Store Data Debug:")
      console.log("storeData.originalStore:", storeData.originalStore)
      console.log("storeData.destinationStore:", storeData.destinationStore)
      console.log("storeData.storesConfirmed:", storeData.storesConfirmed)
      
      const sourceLocation = locations.find((loc) => loc.location_name.toLowerCase() === storeData.originalStore.toLowerCase())
      const destinationLocation = locations.find((loc) => loc.location_name.toLowerCase() === storeData.destinationStore.toLowerCase())

      console.log("🔍 Location Debug Info:")
      console.log("Available locations:", locations.map(loc => `${loc.location_name} (ID: ${loc.location_id})`))
      console.log("Selected original store:", storeData.originalStore)
      console.log("Selected destination store:", storeData.destinationStore)
      console.log("Found source location:", sourceLocation)
      console.log("Found destination location:", destinationLocation)

      if (!sourceLocation || !destinationLocation) {
        console.error("❌ Location validation failed:")
        console.error("Source location found:", !!sourceLocation)
        console.error("Destination location found:", !!destinationLocation)
        toast.error("Invalid location selection")
        setLoading(false)
        return
      }

      // Validate that we're not transferring to the same location
      console.log("🔍 Location Comparison Debug:")
      console.log("Source Location:", sourceLocation.location_name, "(ID:", sourceLocation.location_id, ")")
      console.log("Destination Location:", destinationLocation.location_name, "(ID:", destinationLocation.location_id, ")")
      console.log("Location IDs match:", sourceLocation.location_id === destinationLocation.location_id)
      console.log("Location names match:", sourceLocation.location_name.toLowerCase() === destinationLocation.location_name.toLowerCase())
      
      if (sourceLocation.location_id === destinationLocation.location_id) {
        console.error("❌ Same location transfer detected")
        console.error("Source and destination have the same location ID:", sourceLocation.location_id)
        toast.error("Source and destination cannot be the same")
        setLoading(false)
        return
      }
      
      // Additional safety check: ensure destination is not a warehouse
      if (destinationLocation.location_name.toLowerCase().includes('warehouse')) {
        console.error("❌ Destination is a warehouse location")
        console.error("Destination location:", destinationLocation.location_name)
        toast.error("Cannot transfer to a warehouse location")
        setLoading(false)
        return
      }

      // Find employee ID
      let transferEmployee = staff.find((emp) => emp.name === transferInfo.transferredBy)
      
      // Fallback: if employee not found, use the first available staff member
      if (!transferEmployee && staff.length > 0) {
        console.warn("⚠️ Employee not found, using first available staff member as fallback")
        transferEmployee = staff[0]
        // Update the transferInfo to reflect the actual employee being used
        setTransferInfo(prev => ({
          ...prev,
          transferredBy: transferEmployee.name
        }))
      }
      
      if (!transferEmployee) {
        console.error("❌ No staff members available for transfer")
        toast.error("No staff members available. Please contact administrator.")
        setLoading(false)
        return
      }

      // Prepare transfer data
      const transferData = {
        source_location_id: sourceLocation.location_id,
        destination_location_id: destinationLocation.location_id,
        employee_id: transferEmployee.emp_id,
        status: "approved", // Use 'approved' to match database enum
        delivery_date: transferInfo.deliveryDate || null,
        products: productsToTransfer.map((product) => {
          // Convert quantity to base units
          const baseUnitQuantity = getBaseUnitQuantity(product.product_id, product.transfer_quantity);
          const selectedUnit = selectedUnits[product.product_id];
          
          console.log(`📦 Product: ${product.product_name}`);
          console.log(`   Selected Unit: ${selectedUnit || 'base'}`);
          console.log(`   Input Quantity: ${product.transfer_quantity} ${selectedUnit || 'units'}`);
          console.log(`   Base Unit Quantity: ${baseUnitQuantity} base units`);
          
          return {
            product_id: product.product_id,
            quantity: baseUnitQuantity, // Send in base units
            unit_name: selectedUnit || (product.product_type === 'Medicine' ? 'tablet' : 'piece'),
            display_quantity: product.transfer_quantity, // Original display quantity
          };
        }),
      }

      console.log("📦 Transfer Data Validation:")
      console.log("Source Location ID:", transferData.source_location_id, "Name:", sourceLocation.location_name)
      console.log("Destination Location ID:", transferData.destination_location_id, "Name:", destinationLocation.location_name)
      console.log("Employee ID:", transferData.employee_id, "Name:", transferEmployee.name)
      console.log("Products to transfer:", productsToTransfer.map(p => `${p.product_name} (${p.transfer_quantity} qty)`))
      
      // Double-check convenience store transfer
      if (isConvenienceStoreTransfer) {
        console.log("🏪 Convenience Store Transfer Validation:")
        console.log("Is convenience store transfer:", isConvenienceStoreTransfer)
        console.log("Destination location name:", destinationLocation.location_name)
        console.log("Destination location ID:", destinationLocation.location_id)
        console.log("Expected destination should be convenience store")
      }

      console.log("📦 Sending transfer data:", transferData)
      console.log("📍 Transfer Direction: FROM", storeData.originalStore, "TO", storeData.destinationStore)
      console.log("📦 Products being transferred:", productsToTransfer.map(p => `${p.product_name} (${p.transfer_quantity} qty)`))
      
      // Enhanced FIFO Stock Validation
      console.log("🔍 Performing FIFO stock validation before transfer...")
      toast.info("🔍 Checking FIFO stock availability...")
      
      try {
        await validateFifoStockBeforeTransfer(productsToTransfer, sourceLocation.location_id)
        console.log("✅ FIFO validation completed successfully")
      } catch (fifoError) {
        console.error("❌ FIFO validation failed:", fifoError.message)
        console.log("⚠️ FIFO validation failed, but continuing with transfer...")
        console.log("⚠️ This is a temporary workaround while debugging FIFO issues")
        // Temporarily skip FIFO validation error to allow transfer to proceed
        // toast.error(fifoError.message)
        // setLoading(false)
        // return
      }
      
      // Special confirmation for convenience store transfers
      if (isConvenienceStoreTransfer) {
        console.log("🏪 Confirming convenience store transfer...")
        toast.info("🔄 Processing transfer to convenience store...")
      } else {
        toast.info("🔄 Processing inventory transfer...")
      }
      
      const response = await handleApiCall("create_transfer", transferData)
      console.log("📥 Transfer creation response:", response)

      if (response.success) {
        // Log the transfer activity with user context
        try {
          const userData = JSON.parse(sessionStorage.getItem('user_data') || '{}');
          await api.callGenericAPI('backend.php', 'log_activity', {
            activity_type: 'INVENTORY_TRANSFER_CREATED',
            description: `Transfer created from ${transferInfo.sourceLocation} to ${transferInfo.destinationLocation}: ${selectedProducts.length} products`,
            table_name: 'tbl_transfer_header',
            record_id: response.transfer_header_id,
            user_id: userData.user_id || userData.emp_id,
            username: userData.username,
            role: userData.role,
          });
        } catch (_) {}
        
        const transferredCount = response.products_transferred || 0;
        
        console.log("✅ Transfer successful!")
        console.log("Transfer ID:", response.transfer_id)
        console.log("Products transferred:", transferredCount)
        console.log("Source location:", response.source_location)
        console.log("Destination location:", response.destination_location)
        
        // Simple success message for basic transfer
        let successMessage = `✅ Transfer completed successfully! ${transferredCount} product(s) moved FROM ${storeData.originalStore} TO ${storeData.destinationStore}.`
        
        if (isConvenienceStoreTransfer) {
          successMessage += " Products are now available in the convenience store inventory."
        }
        
        toast.success(successMessage)
        
        console.log("✅ Transfer created with ID:", response.transfer_id)

        // Reset form
        setShowCreateModal(false)
        setCurrentStep(1)
        setStoreData({ 
          originalStore: "Warehouse", 
          destinationStore: "", 
          storesConfirmed: false 
        })
        setTransferInfo({ 
          transferredBy: currentUser?.full_name || "Inventory Manager", 
          receivedBy: "", 
          deliveryDate: new Date().toISOString().split('T')[0] 
        })
        setSelectedProducts([])
        setCheckedProducts([])

        // Increment session transfer count
        incrementSessionTransfers();
        
        // Reload transfers to show the new one
        console.log("🔄 Reloading transfers...")
        await loadTransfers()
        
        // Reload transfer logs to show the new entries
        console.log("🔄 Reloading transfer logs...")
        await loadTransferLogs()
        
        // Force reload of available products to reflect the transfer
        if (sourceLocation) {
          console.log("🔄 Reloading source location products...")
          await loadAvailableProducts(sourceLocation.location_id)
        }
        
        // Special notification for convenience store transfers
        if (isConvenienceStoreTransfer) {
          setTimeout(() => {
            toast.info("🏪 You can now view the transferred products in the Convenience Store inventory page.")
          }, 2000)
        }
      } else {
        console.error("❌ Transfer creation failed:", response.message)
        toast.error(response.message || "Failed to create transfer")
      }
    } catch (error) {
      console.error("Error creating transfer:", error)
      toast.error("Failed to create transfer: " + error.message)
    } finally {
      setLoading(false)
    }
  }

  const handleCreateTransfer = () => {
    setCurrentStep(1)
    setStoreData({ 
      originalStore: "Warehouse", // Always reset to Warehouse
      destinationStore: "", 
      storesConfirmed: false 
    })
    setTransferInfo({ 
      transferredBy: currentUser?.full_name || "Inventory Manager", // Auto-fill with current user or default
      receivedBy: "", 
      deliveryDate: new Date().toISOString().split('T')[0] // Auto-fill with today's date
    })
    setSelectedProducts([])
    setCheckedProducts([])
    setShowCreateModal(true)
  }

  const handleConfirmStores = () => {
    if (!storeData.destinationStore) {
      toast.error("Please select destination store")
      return
    }
    
    // Find the warehouse location ID
    const warehouseLocation = locations.find((loc) => 
      loc.location_name.toLowerCase().includes('warehouse')
    )
    if (!warehouseLocation) {
      toast.error("Warehouse location not found in available locations")
      return
    }
    
    // Set warehouse as the source location
    setStoreData((prev) => ({ 
      ...prev, 
      originalStore: warehouseLocation.location_name,
      storesConfirmed: true 
    }))
    setCurrentStep(2)
    
    // Load products from the warehouse
    loadAvailableProducts(warehouseLocation.location_id)
  }

  const handleNextToProducts = async () => {
    if (!transferInfo.transferredBy) {
      toast.error("Transferred by (Original Store) is required")
      return
    }
    
    // Find warehouse location ID
    const warehouseLocation = locations.find(loc => loc.location_name.toLowerCase().includes('warehouse'))
    if (warehouseLocation) {
      console.log("🏭 Loading warehouse products for transfer...")
      await loadAvailableProducts(warehouseLocation.location_id)
    } else {
      console.warn("⚠️ Warehouse location not found")
      toast.error("Warehouse location not found")
      return
    }
    
    setCurrentStep(3)
  }

  const handleProductCheck = (productId, checked) => {
    if (checked) {
      setCheckedProducts((prev) => [...prev, productId])
    } else {
      setCheckedProducts((prev) => prev.filter((id) => id !== productId))
    }
  }

  const handleSelectProducts = async () => {
    const selected = availableProducts
      .filter((p) => checkedProducts.includes(p.product_id))
      .map((p) => ({
        ...p,
        transfer_quantity: 0,
      }))
    setSelectedProducts(selected)
    
    // Load units for the selected products
    console.log("🔄 Loading units for selected products...");
    await loadUnitsForProducts(selected);
    
    setShowProductSelection(false)
  }

  const updateTransferQuantity = (productId, quantity) => {
    const newQuantity = Number.parseInt(quantity) || 0;
    
    // Find the product to check available quantity from all batches (not just oldest)
    const product = selectedProducts.find(p => p.product_id === productId);
    const totalAvailableQty = product?.total_quantity || product?.available_for_transfer || 0;
    const oldestBatchQty = product?.oldest_batch_quantity || 0;
    
    // Allow any quantity input - don&apos;t automatically reduce it
    const finalQuantity = newQuantity;
    
    // Show different warnings based on quantity (but don&apos;t auto-reduce)
    if (newQuantity > totalAvailableQty) {
      toast.warning(`⚠️ Warning: You're trying to transfer ${newQuantity} units but only ${totalAvailableQty} are available. Transfer may fail if insufficient stock.`);
    } else if (newQuantity > oldestBatchQty && oldestBatchQty > 0) {
      const additionalBatches = Math.ceil((newQuantity - oldestBatchQty) / oldestBatchQty) + 1;
      toast.info(`⚡ Automatic batch switching: Will consume from ${additionalBatches} batches in FIFO order`);
    }
    
    setSelectedProducts((prev) =>
      prev.map((product) => {
        if (product.product_id === productId) {
          return { ...product, transfer_quantity: finalQuantity };
        }
        return product;
      }),
    )
  }

  // Handle unit selection change
  const handleUnitChange = (productId, unitName) => {
    console.log(`🔄 Changing unit for product ${productId} to ${unitName}`);
    
    setSelectedUnits(prev => ({
      ...prev,
      [productId]: unitName
    }));
    
    // Reset transfer quantity when unit changes
    setSelectedProducts((prev) =>
      prev.map((product) => {
        if (product.product_id === productId) {
          return { ...product, transfer_quantity: 0 };
        }
        return product;
      }),
    );
    
    toast.info(`✅ Unit changed to ${unitName}. Please enter quantity again.`);
  }

  // Get the actual quantity in base units for transfer
  const getBaseUnitQuantity = (productId, quantity) => {
    const selectedUnit = selectedUnits[productId];
    const units = productUnits[productId];
    
    if (!selectedUnit || !units) {
      return quantity; // Return as-is if no unit data
    }
    
    const unit = units.find(u => u.unit_name === selectedUnit);
    if (!unit) {
      return quantity;
    }
    
    // Convert to base units
    return quantity * unit.unit_quantity;
  }

  const removeProduct = (productId) => {
    setSelectedProducts((prev) => prev.filter((product) => product.product_id !== productId))
    setCheckedProducts((prev) => prev.filter((id) => id !== productId))
  }

  const handleStatusUpdate = async (transferId, currentStatus) => {
    // Since transfers are now approved immediately, we'll just show a message
    toast.info("Transfer status: " + (currentStatus === "approved" ? "Completed" : currentStatus) + " - Products have been transferred to destination store")
  }

  const handleDeleteTransfer = async (transferId) => {
    try {
      const response = await handleApiCall("delete_transfer", {
        transfer_header_id: transferId,
      })

      if (response.success) {
        // Log the transfer deletion
        try {
          await api.callGenericAPI('backend.php', 'log_activity', {
            activity_type: 'INVENTORY_TRANSFER_DELETED',
            description: `Transfer deleted (ID: ${transferId})`,
            table_name: 'tbl_transfer_header',
            record_id: transferId,
          });
        } catch (_) {}
        
        toast.success("Transfer deleted successfully")
        loadTransfers()
        setShowDeleteModal(false)
        setTransferToDelete(null)
      } else {
        toast.error(response.message || "Failed to delete transfer")
      }
    } catch (error) {
      // Log the error
      try {
        await api.callGenericAPI('backend.php', 'log_activity', {
          activity_type: 'INVENTORY_TRANSFER_DELETE_ERROR',
          description: `Failed to delete transfer ID ${transferId}: ${error.message}`,
          table_name: 'tbl_transfer_header',
          record_id: transferId,
        });
      } catch (_) {}
      
      console.error("Error deleting transfer:", error)
      toast.error("Failed to delete transfer")
    }
  }

  const filteredTransfers = transfers.filter(
    (transfer) => {
      const matchesSearch = 
        transfer.transfer_header_id?.toString().toLowerCase().includes(searchTerm.toLowerCase()) ||
        transfer.source_location_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        transfer.destination_location_name?.toLowerCase().includes(searchTerm.toLowerCase());
      
      const matchesDate = !dateFilter || 
        (transfer.date && new Date(transfer.date).toISOString().split('T')[0] === dateFilter);
      
      return matchesSearch && matchesDate;
    }
  )

  const filteredProducts = availableProducts.filter((product) => {
    const matchesSearch =
      product.product_name.toLowerCase().includes(productSearchTerm.toLowerCase()) ||
      (product.barcode && String(product.barcode).includes(productSearchTerm)) ||
      (product.sku && product.sku.toLowerCase().includes(productSearchTerm.toLowerCase()))

    const matchesCategory = selectedCategory === "All Product Category" || product.category === selectedCategory
    const matchesSupplier = selectedSupplier === "All Suppliers" || product.supplier_name === selectedSupplier

    return matchesSearch && matchesCategory && matchesSupplier
  })

  // Get unique categories and suppliers from warehouse products
  const categories = [...new Set(availableProducts.map((p) => {
    // Handle both string and object category formats
    if (typeof p.category === 'string') {
      return p.category;
    } else if (p.category && typeof p.category === 'object' && p.category.category_name) {
      return p.category.category_name;
    }
    return null;
  }).filter(Boolean))]
  const suppliers = [...new Set(availableProducts.map((p) => p.supplier_name).filter(Boolean))]

  // Main Transfer List View
  if (!showCreateModal) {
    return (
      <div className={`p-6 min-h-screen ${isDarkMode ? 'bg-slate-900' : 'bg-gray-50'}`}>
        {/* Header */}
        <div className="mb-6">
          <div className={`flex items-center text-sm mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>
            <span>Inventory Management</span>
            <div className="mx-2">{">"}</div>
            <span className={isDarkMode ? 'text-blue-400' : 'text-blue-600'}>Inventory Transfer</span>
          </div>
          <div className="flex items-center gap-3">
            <h1 className={`text-2xl font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Inventory Transfer</h1>
            <div className={`flex items-center gap-2 px-3 py-1 rounded-full text-sm font-medium ${isDarkMode ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800'}`}>
              <span>🔄</span>
              <span>FIFO System Active</span>
            </div>
          </div>
        </div>

        {/* Action Bar */}
        <div className={`rounded-lg shadow-sm border p-4 mb-6 ${isDarkMode ? 'bg-slate-800 border-slate-600' : 'bg-white border-gray-200'}`}>
          <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div className="flex flex-wrap items-center gap-3">
              <div className="relative">
                <Search className={`absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 ${isDarkMode ? 'text-slate-400' : 'text-gray-400'}`} />
                <input
                  type="text"
                  placeholder="Search transfers..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className={`pl-10 pr-4 py-2 w-64 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'bg-slate-700 border-slate-500 text-white placeholder-slate-300' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                />
              </div>
              <div className="relative">
                <input
                  type="date"
                  placeholder="Filter by date..."
                  value={dateFilter}
                  onChange={(e) => setDateFilter(e.target.value)}
                  className={`px-4 py-2 w-48 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'bg-slate-700 border-slate-500 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                />
                {dateFilter && (
                  <button
                    onClick={() => setDateFilter("")}
                    className={`absolute right-2 top-1/2 transform -translate-y-1/2 ${isDarkMode ? 'text-slate-400 hover:text-slate-200' : 'text-gray-400 hover:text-gray-600'}`}
                  >
                    <X className="h-4 w-4" />
                  </button>
                )}
              </div>
            </div>
            <div className="flex items-center gap-2">

              <button
                onClick={handleCreateTransfer}
                className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md flex items-center gap-2 whitespace-nowrap"
              >
                <Plus className="h-4 w-4" />
                <span>Create Transfer</span>
              </button>

            </div>
          </div>
        </div>

                
        <div className={`rounded-lg shadow-sm border p-6 mb-6 ${isDarkMode ? 'bg-slate-800 border-slate-600' : 'bg-white border-gray-200'}`}>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div className={`text-center p-4 rounded-lg border ${isDarkMode ? 'bg-blue-900/20 border-blue-700' : 'bg-blue-50 border-blue-200'}`}>
              <div className={`text-3xl font-bold ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`}>
                {calculateTotalProductsTransferred()}
              </div>
              <div className={`text-sm mt-1 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>Total Products Transferred</div>
              <div className={`text-xs mt-1 ${isDarkMode ? 'text-blue-400' : 'text-blue-500'}`}>
                {getTransferStatistics().averageProductsPerTransfer} avg per transfer
              </div>
            </div>
            <div className={`text-center p-4 rounded-lg border ${isDarkMode ? 'bg-purple-900/20 border-purple-700' : 'bg-purple-50 border-purple-200'}`}>
              <div className={`text-3xl font-bold ${isDarkMode ? 'text-purple-400' : 'text-purple-600'}`}>
                {transfers.length}
              </div>
              <div className={`text-sm mt-1 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>Total Transfer Records</div>
              <div className={`text-xs mt-1 ${isDarkMode ? 'text-purple-400' : 'text-purple-500'}`}>
                {getSessionTransferStats().sessionTransfers} this session ({getSessionTransferStats().sessionDuration} min)
              </div>
            </div>
            <div className={`text-center p-4 rounded-lg border ${isDarkMode ? 'bg-green-900/20 border-green-700' : 'bg-green-50 border-green-200'}`}>
              <div className={`text-2xl font-bold ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>
                🔄
              </div>
              <div className={`text-sm mt-1 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>FIFO Transfer System</div>
              <div className={`text-xs mt-1 ${isDarkMode ? 'text-green-400' : 'text-green-500'}`}>
                Oldest batches transferred first
              </div>
            </div>
            <div className={`text-center p-4 rounded-lg border ${isDarkMode ? 'bg-orange-900/20 border-orange-700' : 'bg-orange-50 border-orange-200'}`}>
              <div className={`text-3xl font-bold ${isDarkMode ? 'text-orange-400' : 'text-orange-600'}`}>
                {(() => {
                  const expiring = checkForExpiringProducts(availableProducts);
                  const expiredCount = expiring.filter(p => p.expiryInfo.isExpired).length;
                  const criticalCount = expiring.filter(p => p.expiryInfo.isCritical).length;
                  return expiredCount > 0 ? expiredCount : criticalCount;
                })()}
              </div>
              <div className={`text-sm mt-1 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>Expiration Alerts</div>
              <div className={`text-xs mt-1 ${isDarkMode ? 'text-orange-400' : 'text-orange-500'}`}>
                {(() => {
                  const expiring = checkForExpiringProducts(availableProducts);
                  const expiredCount = expiring.filter(p => p.expiryInfo.isExpired).length;
                  const criticalCount = expiring.filter(p => p.expiryInfo.isCritical).length;
                  if (expiredCount > 0) return `${expiredCount} expired`;
                  if (criticalCount > 0) return `${criticalCount} critical`;
                  return "No alerts";
                })()}
              </div>
            </div>
          </div>

        </div>
        {/* Transfer Log Table */}
        <div className={`rounded-lg shadow-sm border mb-6 ${isDarkMode ? 'bg-slate-800 border-slate-600' : 'bg-white border-gray-200'}`}>
          <div className={`p-4 border-b ${isDarkMode ? 'border-slate-600' : 'border-gray-200'}`}>
            <h3 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Transfer Log Details</h3>
            <p className={`text-sm mt-1 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>Detailed log of all product transfers with individual item tracking</p>
          </div>
          <div className="overflow-x-auto max-h-96">
            <table className="w-full min-w-max" style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
              <thead className={`border-b sticky top-0 z-10 ${isDarkMode ? 'bg-slate-700 border-slate-600' : 'bg-indigo-50 border-gray-200'}`}>
                <tr>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    Transfer ID
                  </th>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    Product
                  </th>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    From Location
                  </th>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    To Location
                  </th>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    Quantity
                  </th>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    Transfer Date
                  </th>
                  <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    Logged At
                  </th>
                  <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                    Action
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${isDarkMode ? 'bg-slate-800 divide-slate-600' : 'bg-white divide-gray-200'}`}>
                {loading ? (
                  <tr>
                    <td colSpan={8} className={`px-6 py-4 text-center ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                      Loading transfer logs...
                    </td>
                  </tr>
                ) : transferLogs.length > 0 ? (
                  transferLogs.map((log, index) => (
                    <tr key={index} className={`${isDarkMode ? 'hover:bg-slate-700' : 'hover:bg-gray-50'}`}>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm font-medium ${isDarkMode ? 'text-indigo-400' : 'text-indigo-600'}`}>
                        TR-{log.transfer_id}
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        <div className="flex flex-col">
                          <span className="font-medium">{log.product_name || `Product ID: ${log.product_id}`}</span>
                          <span className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>ID: {log.product_id}</span>
                        </div>
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${isDarkMode ? 'bg-red-900/20 text-red-300' : 'bg-red-100 text-red-800'}`}>
                          {log.from_location}
                        </span>
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${isDarkMode ? 'bg-green-900/20 text-green-300' : 'bg-green-100 text-green-800'}`}>
                          {log.to_location}
                        </span>
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        <span className={`inline-flex px-3 py-1 text-sm font-semibold rounded-full ${isDarkMode ? 'bg-blue-900/20 text-blue-300' : 'bg-blue-100 text-blue-800'}`}>
                          {log.quantity} units
                        </span>
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        <div className="flex flex-col">
                          <span className="font-medium">
                            {new Date(log.transfer_date).toLocaleDateString()}
                          </span>
                          <span className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                            {new Date(log.transfer_date).toLocaleTimeString()}
                          </span>
                        </div>
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                        <div className="flex flex-col">
                          <span className="font-medium">
                            {new Date(log.created_at).toLocaleDateString()}
                          </span>
                          <span className={`text-xs ${isDarkMode ? 'text-slate-500' : 'text-gray-400'}`}>
                            {new Date(log.created_at).toLocaleTimeString()}
                          </span>
                        </div>
                      </td>
                      <td className={`px-6 py-4 whitespace-nowrap text-center ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                        <button
                          onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log("ℹ️ Details icon clicked for transfer:", log);
                            console.log("🔍 Fetching fresh transfer details including batch info...");
                            console.log("📋 Transfer ID:", log.transfer_id);
                            console.log("📦 Product ID:", log.product_id);
                            console.log("🏷️ Product Name:", log.product_name);
                            
                            (async () => {
                              try {
                                toast.info("🔍 Loading transfer details...");
                                const full = await handleApiCall("get_transfer_log_by_id", { transfer_id: log.transfer_id });
                                console.log("📥 API Response:", full);
                                
                                if (full?.success && full?.data) {
                                  console.log("✅ Transfer details loaded successfully");
                                  console.log("📦 Product Name:", full.data.product_name);
                                  console.log("🔢 Batch Details Count:", full.data.batch_details?.length || 0);
                                  console.log("📊 Batch Details:", full.data.batch_details);
                                  
                                  setSelectedTransferForBatchDetails(full.data);
                                  toast.success(`✅ Loaded details for ${full.data.product_name}`);
                                } else {
                                  console.warn("⚠️ API returned error or no data, using log data as fallback");
                                  console.log("⚠️ API Response:", full);
                                  
                                  // Use the log data with empty batch_details if API fails
                                  setSelectedTransferForBatchDetails({
                                    ...log,
                                    batch_details: []
                                  });
                                  toast.warning("⚠️ Using basic transfer info (batch details unavailable)");
                                }
                              } catch (error) {
                                console.error("❌ Error loading transfer details:", error);
                                setSelectedTransferForBatchDetails({
                                  ...log,
                                  batch_details: []
                                });
                                toast.error("❌ Failed to load batch details");
                              } finally {
                                setShowBatchDetailsModal(true);
                                console.log("✅ Modal state set - should show modal now");
                                
                                // Debug: Check modal state after a short delay
                                setTimeout(() => {
                                  console.log("🔍 Modal State Check:");
                                  console.log("  - showBatchDetailsModal:", showBatchDetailsModal);
                                  console.log("  - selectedTransferForBatchDetails:", selectedTransferForBatchDetails);
                                }, 100);
                              }
                            })();
                          }}
                          className={`flex items-center gap-2 px-4 py-2 rounded-lg border-2 transition-all duration-200 hover:shadow-md ${
                            isDarkMode 
                              ? 'bg-slate-800 border-slate-600 hover:border-blue-500 text-blue-400 hover:text-blue-300' 
                              : 'bg-white border-gray-200 hover:border-blue-500 text-blue-600 hover:text-blue-700'
                          }`}
                          title={`View Product & Batch Details for ${log.product_name}`}
                        >
                          <Info className="h-4 w-4" />
                          <span className="text-sm font-medium">View Details</span>
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-8 text-center">
                      <div className="flex flex-col items-center space-y-3">
                        <div className={`h-12 w-12 rounded-full flex items-center justify-center ${isDarkMode ? 'bg-indigo-900/20' : 'bg-indigo-100'}`}>
                          <span className={`text-xl ${isDarkMode ? 'text-indigo-400' : 'text-indigo-600'}`}>📊</span>
                        </div>
                        <div className={isDarkMode ? 'text-slate-400' : 'text-gray-500'}>
                          <p className="text-lg font-medium">No transfer logs found</p>
                          <p className="text-sm">Transfer logs will appear here after transfers are created</p>
                        </div>
                      </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Delete Confirmation Modal */}
        {showDeleteModal && (
          <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
            <div className={`bg-transparent backdrop-blur-sm rounded-lg shadow-xl p-6 w-96 border-2 ${isDarkMode ? 'border-slate-500' : 'border-gray-400'}`}>
              <h3 className={`text-lg font-semibold mb-4 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Delete Transfer</h3>
              <p className={`mb-4 ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>
                Are you sure you want to delete transfer TR-{transferToDelete?.transfer_header_id}? This action cannot
                be undone.
              </p>
              <div className="flex justify-end space-x-4">
                <button
                  onClick={() => {
                    setShowDeleteModal(false)
                    setTransferToDelete(null)
                  }}
                  className={`px-4 py-2 border rounded-md ${isDarkMode ? 'border-slate-500 hover:bg-slate-700 text-slate-300' : 'border-gray-300 hover:bg-gray-50 text-gray-700'}`}
                >
                  Cancel
                </button>
                <button
                  onClick={() => handleDeleteTransfer(transferToDelete.transfer_header_id)}
                  className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md"
                >
                  Delete
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Batch Details Modal (also render in list view) */}
        {showBatchDetailsModal && selectedTransferForBatchDetails && (
          <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50" style={{ zIndex: 9999 }}>
            <div className={`rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto ${isDarkMode ? 'bg-slate-800' : 'bg-white'}`}>
              {/* Header */}
              <div className={`flex items-center justify-between p-6 border-b ${isDarkMode ? 'border-slate-600' : 'border-gray-200'}`}>
                <h3 className={`text-2xl font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  📦 Batch Details - {selectedTransferForBatchDetails.product_name}
                </h3>
                <div className="flex items-center gap-2">
                  <button 
                    onClick={closeBatchDetailsModal}
                    className={`p-2 ${isDarkMode ? 'text-slate-400 hover:text-slate-200' : 'text-gray-500 hover:text-gray-700'}`}
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
                  <div className={`border rounded-lg p-4 ${isDarkMode ? 'bg-blue-900/20 border-blue-700' : 'bg-blue-50 border-blue-200'}`}>
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-lg ${isDarkMode ? 'bg-blue-800/30' : 'bg-blue-100'}`}>
                        <Package className={`h-6 w-6 ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`} />
                      </div>
                      <div className="flex-1">
                        <h4 className={`font-semibold text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                          {selectedTransferForBatchDetails.product_name || 'Product Name Unavailable'}
                        </h4>
                        <p className={`text-sm ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>
                          Product ID: {selectedTransferForBatchDetails.product_id || 'N/A'}
                        </p>
                        {selectedTransferForBatchDetails.category && (
                          <p className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                            Category: {selectedTransferForBatchDetails.category}
                          </p>
                        )}
                        {selectedTransferForBatchDetails.brand && (
                          <p className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                            Brand: {selectedTransferForBatchDetails.brand}
                          </p>
                        )}
                        <p className={`text-lg font-bold mt-1 ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`}>
                          Transfer ID: TR-{selectedTransferForBatchDetails.transfer_id}
                        </p>
                      </div>
                    </div>
                  </div>

                  {/* Transfer Info Card */}
                  <div className={`border rounded-lg p-4 ${isDarkMode ? 'bg-green-900/20 border-green-700' : 'bg-green-50 border-green-200'}`}>
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-lg ${isDarkMode ? 'bg-green-800/30' : 'bg-green-100'}`}>
                        <CheckCircle className={`h-6 w-6 ${isDarkMode ? 'text-green-400' : 'text-green-600'}`} />
                      </div>
                      <div>
                        <h4 className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Transfer Details</h4>
                        <p className={`text-sm ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>Quantity: {selectedTransferForBatchDetails.quantity} units</p>
                        <p className={`text-sm ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>From: {selectedTransferForBatchDetails.from_location}</p>
                        <p className={`text-sm ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>To: {selectedTransferForBatchDetails.to_location}</p>
                      </div>
                    </div>
                  </div>

                  {/* Date Info Card */}
                  <div className={`border rounded-lg p-4 ${isDarkMode ? 'bg-purple-900/20 border-purple-700' : 'bg-purple-50 border-purple-200'}`}>
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-lg ${isDarkMode ? 'bg-purple-800/30' : 'bg-purple-100'}`}>
                        <Clock className={`h-6 w-6 ${isDarkMode ? 'text-purple-400' : 'text-purple-600'}`} />
                      </div>
                      <div>
                        <h4 className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Transfer Date</h4>
                        <p className={`text-sm ${isDarkMode ? 'text-purple-400' : 'text-purple-600'}`}>
                          {new Date(selectedTransferForBatchDetails.transfer_date).toLocaleDateString()}
                        </p>
                        <p className={`text-sm ${isDarkMode ? 'text-purple-400' : 'text-purple-600'}`}>
                          {new Date(selectedTransferForBatchDetails.transfer_date).toLocaleTimeString()}
                        </p>
                      </div>
                    </div>
                  </div>

                  {/* Batch Info Card */}
                  <div className={`border rounded-lg p-4 ${isDarkMode ? 'bg-orange-900/20 border-orange-700' : 'bg-orange-50 border-orange-200'}`}>
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-lg ${isDarkMode ? 'bg-orange-800/30' : 'bg-orange-100'}`}>
                        <Package className={`h-6 w-6 ${isDarkMode ? 'text-orange-400' : 'text-orange-600'}`} />
                      </div>
                      <div>
                        <h4 className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Batch Information</h4>
                        <p className={`text-sm ${isDarkMode ? 'text-orange-400' : 'text-orange-600'}`}>
                          Batches Used: {selectedTransferForBatchDetails.batch_details?.length || 0}
                        </p>
                        <p className={`text-sm ${isDarkMode ? 'text-orange-400' : 'text-orange-600'}`}>
                          FIFO Order: Active
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Batch Details Table */}
                <div className={`border rounded-lg ${isDarkMode ? 'bg-slate-800 border-slate-600' : 'bg-white border-gray-200'}`}>
                  <div className={`px-6 py-4 border-b ${isDarkMode ? 'border-slate-600' : 'border-gray-200'}`}>
                    <h4 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Batch Consumption Details</h4>
                    <p className={`text-sm ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>Showing which batches were consumed for this transfer</p>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full">
                      <thead className={isDarkMode ? 'bg-slate-700' : 'bg-gray-50'}>
                        <tr>
                          <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>Batch Number</th>
                          <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>Batch Reference</th>
                          <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>Quantity Used</th>
                          <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>SRP</th>
                          <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>Expiry Date</th>
                          <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>Status</th>
                        </tr>
                      </thead>
                      <tbody className={`divide-y ${isDarkMode ? 'bg-slate-800 divide-slate-600' : 'bg-white divide-gray-200'}`}>
                        {selectedTransferForBatchDetails.batch_details && selectedTransferForBatchDetails.batch_details.length > 0 ? (
                          selectedTransferForBatchDetails.batch_details.map((batch, index) => (
                            <tr key={index} className={isDarkMode ? 'hover:bg-slate-700' : 'hover:bg-gray-50'}>
                              <td className={`px-6 py-4 whitespace-nowrap text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                {batch.batch_number || batch.batch_id || `B-${selectedTransferForBatchDetails.transfer_id}-${index + 1}`}
                              </td>
                              <td className={`px-6 py-4 whitespace-nowrap text-sm ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>
                                {batch.batch_reference || `BR-${selectedTransferForBatchDetails.transfer_id}-${selectedTransferForBatchDetails.product_id}`}
                              </td>
                              <td className={`px-6 py-4 whitespace-nowrap text-center text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${isDarkMode ? 'bg-blue-900/30 text-blue-300' : 'bg-blue-100 text-blue-800'}`}>
                                  {batch.quantity || batch.batch_quantity || 0} units
                                </span>
                              </td>
                              <td className={`px-6 py-4 whitespace-nowrap text-center text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                ₱{Number.parseFloat(batch.batch_srp || batch.srp || 0).toFixed(2)}
                              </td>
                              <td className={`px-6 py-4 whitespace-nowrap text-center text-sm ${isDarkMode ? 'text-slate-300' : 'text-gray-500'}`}>
                                {batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A'}
                              </td>
                              <td className="px-6 py-4 whitespace-nowrap text-center">
                                <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${isDarkMode ? 'bg-green-900/30 text-green-300' : 'bg-green-100 text-green-800'}`}>
                                  Consumed
                                </span>
                              </td>
                            </tr>
                          ))
                        ) : (
                          <tr>
                            <td colSpan={6} className={`px-6 py-8 text-center ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                              <div className="flex flex-col items-center space-y-3">
                                <Package className={`h-12 w-12 ${isDarkMode ? 'text-slate-500' : 'text-gray-400'}`} />
                                <p className={`text-lg font-medium ${isDarkMode ? 'text-slate-300' : 'text-gray-900'}`}>No detailed batch information available</p>
                                <p className={`text-sm ${isDarkMode ? 'text-slate-400' : 'text-gray-600'}`}>
                                  This transfer may not have detailed batch tracking or the batch details were not recorded.
                                </p>
                                <div className={`mt-2 p-3 rounded-lg border ${isDarkMode ? 'bg-blue-900/20 border-blue-700' : 'bg-blue-50 border-blue-200'}`}>
                                  <p className={`text-sm ${isDarkMode ? 'text-blue-300' : 'text-blue-700'}`}>
                                    <strong>Basic Transfer Info:</strong>
                                  </p>
                                  <ul className={`text-xs mt-2 space-y-1 ${isDarkMode ? 'text-blue-200' : 'text-blue-600'}`}>
                                    <li>• Product: {selectedTransferForBatchDetails.product_name}</li>
                                    <li>• Quantity: {selectedTransferForBatchDetails.quantity} units</li>
                                    <li>• From: {selectedTransferForBatchDetails.from_location}</li>
                                    <li>• To: {selectedTransferForBatchDetails.to_location}</li>
                                    <li>• Date: {new Date(selectedTransferForBatchDetails.transfer_date).toLocaleString()}</li>
                                  </ul>
                                </div>
                              </div>
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* Summary */}
                <div className={`mt-6 p-4 rounded-lg ${isDarkMode ? 'bg-slate-700' : 'bg-gray-50'}`}>
                  <h4 className={`font-semibold mb-2 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Transfer Summary</h4>
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                      <span className={isDarkMode ? 'text-slate-300' : 'text-gray-600'}>Total Quantity:</span>
                      <span className={`ml-2 font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{selectedTransferForBatchDetails.quantity} units</span>
                    </div>
                    <div>
                      <span className={isDarkMode ? 'text-slate-300' : 'text-gray-600'}>Batches Used:</span>
                      <span className={`ml-2 font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{selectedTransferForBatchDetails.batch_details?.length || 0}</span>
                    </div>
                    <div>
                      <span className={isDarkMode ? 'text-slate-300' : 'text-gray-600'}>Transfer Date:</span>
                      <span className={`ml-2 font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        {new Date(selectedTransferForBatchDetails.transfer_date).toLocaleDateString()}
                      </span>
                    </div>
                    <div>
                      <span className={isDarkMode ? 'text-slate-300' : 'text-gray-600'}>FIFO Order:</span>
                      <span className={`ml-2 font-semibold ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>✓ Active</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

      </div>
    )
  }

  // Create Transfer Modal (Steps 1-3)
  return (
    <div className={`p-6 min-h-screen ${isDarkMode ? 'bg-slate-900' : 'bg-gray-50'}`}>
      {/* Header */}
      <div className="mb-6">
        <div className={`flex items-center text-sm mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>
          <span>Inventory Management</span>
          <div className="mx-2">{">"}</div>
          <span>Inventory Transfer</span>
          <div className="mx-2">{">"}</div>
          <span className={isDarkMode ? 'text-blue-400' : 'text-blue-600'}>Create Transfer</span>
        </div>
        <div className="flex items-center justify-between">
          <h1 className={`text-2xl font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
            Create Transfer <span className="text-sm font-normal text-red-500">*Required</span>
          </h1>
          <button
            onClick={() => setShowCreateModal(false)}
            className={`flex items-center gap-2 ${isDarkMode ? 'text-slate-400 hover:text-slate-200' : 'text-gray-400 hover:text-gray-600'}`}
          >
            <X className="h-5 w-5" />
            <span>Back to Transfers</span>
          </button>
        </div>
      </div>

      <div className={`rounded-lg shadow-sm border ${isDarkMode ? 'bg-slate-800 border-slate-600' : 'bg-white border-gray-200'}`}>
        <div className="p-6 space-y-6">
          {/* Step 1: Transfer Stores */}
          {currentStep === 1 && (
            <div>
              <h4 className={`text-xl font-semibold mb-6 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                <span className={`rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2 ${isDarkMode ? 'bg-slate-600 text-slate-200' : 'bg-gray-900 text-white'}`}>
                  1
                </span>
                Transfer stores
              </h4>
              
              {/* Transfer Direction Guide */}
              <div className={`mb-6 p-4 border rounded-lg ${isDarkMode ? 'bg-blue-900/20 border-blue-700' : 'bg-blue-50 border-blue-200'}`}>
                <div className="flex items-center mb-2">
                  <Truck className={`h-5 w-5 mr-2 ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`} />
                  <span className={`font-medium ${isDarkMode ? 'text-blue-200' : 'text-blue-900'}`}>Transfer Direction Guide</span>
                </div>
                <div className={`text-sm space-y-1 ${isDarkMode ? 'text-blue-300' : 'text-blue-700'}`}>
                  <p>• <strong>Warehouse → Convenience Store:</strong> Move products from warehouse to convenience store for retail</p>
                  <p>• <strong>Warehouse → Pharmacy:</strong> Move products from warehouse to pharmacy for prescription sales</p>
                  <p>• <strong>Warehouse → Other Locations:</strong> Move products from warehouse to other store locations</p>
                </div>
                <div className={`mt-2 p-2 border rounded text-xs ${isDarkMode ? 'bg-green-900/20 border-green-700 text-green-300' : 'bg-green-50 border-green-200 text-green-700'}`}>
                  <div className="flex items-center">
                    <CheckCircle className="h-3 w-3 mr-1" />
                    <span>Warehouse is automatically set as the source location</span>
                  </div>
                </div>
              </div>
              
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                  <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>Original Store*</label>
                  <div className={`w-full px-3 py-2 border rounded-md flex items-center ${isDarkMode ? 'border-slate-500 bg-slate-700 text-slate-300' : 'border-gray-300 bg-gray-50 text-gray-700'}`}>
                    <span className="font-medium">Warehouse</span>
                    <span className={`ml-2 text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>(Automatically set)</span>
                  </div>
                  <p className={`text-xs mt-1 ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>Warehouse is automatically selected as the source location</p>
                </div>
                <div>
                  <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>Destination Store*</label>
                  <select
                    value={storeData.destinationStore}
                    onChange={(e) => setStoreData((prev) => ({ ...prev, destinationStore: e.target.value }))}
                    disabled={storeData.storesConfirmed}
                    className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'border-slate-500 bg-slate-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                  >
                    <option value="">Select Destination Store</option>
                    {(() => {
                      const filteredLocations = locations.filter((loc) => !loc.location_name.toLowerCase().includes('warehouse'));
                      console.log("🔍 Destination Dropdown Debug:");
                      console.log("All locations:", locations.map(loc => `${loc.location_name} (ID: ${loc.location_id})`));
                      console.log("Filtered locations:", filteredLocations.map(loc => `${loc.location_name} (ID: ${loc.location_id})`));
                      return filteredLocations.map((loc) => (
                        <option key={loc.location_id} value={loc.location_name}>
                          {loc.location_name}
                        </option>
                      ));
                    })()}
                  </select>
                  
                  {/* Convenience Store Indicator */}
                  {storeData.destinationStore && storeData.destinationStore.toLowerCase().includes('convenience') && (
                    <div className={`mt-2 p-2 border rounded text-sm ${isDarkMode ? 'bg-green-900/20 border-green-700 text-green-300' : 'bg-green-50 border-green-200 text-green-700'}`}>
                      <div className="flex items-center">
                        <CheckCircle className="h-4 w-4 mr-1" />
                        <span>Products will be available for retail sales in convenience store</span>
                      </div>
                    </div>
                  )}
                </div>
              </div>
              
              {/* Transfer Preview */}
              {storeData.originalStore && storeData.destinationStore && storeData.originalStore !== storeData.destinationStore && (
                <div className={`mb-6 p-4 border rounded-lg ${isDarkMode ? 'bg-slate-700 border-slate-600' : 'bg-gray-50 border-gray-200'}`}>
                  <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-4">
                      <div className="text-center">
                        <div className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{storeData.originalStore}</div>
                        <div className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>Source</div>
                      </div>
                      <div className="flex items-center">
                        <Truck className={`h-5 w-5 ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`} />
                        <div className={`mx-2 ${isDarkMode ? 'text-slate-400' : 'text-gray-400'}`}>→</div>
                      </div>
                      <div className="text-center">
                        <div className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{storeData.destinationStore}</div>
                        <div className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>Destination</div>
                      </div>
                    </div>
                    {storeData.destinationStore.toLowerCase().includes('convenience') && (
                      <div className={`flex items-center ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>
                        <Package className="h-4 w-4 mr-1" />
                        <span className="text-sm font-medium">Retail Ready</span>
                      </div>
                    )}
                  </div>
                </div>
              )}
              
              {!storeData.storesConfirmed ? (
                <div className="text-center">
                  <button
                    onClick={handleConfirmStores}
                    className="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-md font-medium"
                  >
                    Confirm transfer stores
                  </button>
                </div>
              ) : (
                <div className="text-center mt-2">
                  <button
                    onClick={() => setStoreData((prev) => ({ ...prev, storesConfirmed: false }))}
                    className={`underline text-sm ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`}
                  >
                    Edit selected stores
                  </button>
                </div>
              )}
            </div>
          )}

          {/* Step 2: Transfer Information */}
          {currentStep === 2 && (
            <div>
              <h4 className={`text-xl font-semibold mb-6 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                <span className={`rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2 ${isDarkMode ? 'bg-slate-600 text-slate-200' : 'bg-gray-900 text-white'}`}>
                  2
                </span>
                Transfer information
              </h4>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                  <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>
                    Transferred by (Original Store)*
                  </label>
                  <div className={`w-full px-3 py-2 border rounded-md ${isDarkMode ? 'border-slate-500 bg-slate-700' : 'border-gray-300 bg-gray-50'}`}>
                    <div className="flex items-center justify-between">
                      <span className={`font-medium ${isDarkMode ? 'text-slate-200' : 'text-gray-900'}`}>
                        {transferInfo.transferredBy || "Loading..."}
                      </span>
                      {currentUser ? (
                        <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${isDarkMode ? 'bg-green-900/20 text-green-300' : 'bg-green-100 text-green-800'}`}>
                          ✓ Logged In
                        </span>
                      ) : (
                        <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${isDarkMode ? 'bg-yellow-900/20 text-yellow-300' : 'bg-yellow-100 text-yellow-800'}`}>
                          ⚠ Using Default
                        </span>
                      )}
                    </div>
                  </div>
                  <p className={`text-xs mt-1 ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                    {currentUser 
                      ? `Logged in as ${currentUser.username || 'user'} - your transfers will be tracked to your account` 
                      : "No active login session - using staff member from inventory team. Login to track transfers to your account."
                    }
                  </p>
                </div>
                <div>
                  <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>
                    Received by (Destination Store)
                  </label>
                  <select
                    value={transferInfo.receivedBy}
                    onChange={(e) => setTransferInfo((prev) => ({ ...prev, receivedBy: e.target.value }))}
                    className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'border-slate-500 bg-slate-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                  >
                    <option value="">Select staff member</option>
                    {staff.map((member) => (
                      <option key={member.emp_id} value={member.name}>
                        {member.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-slate-300' : 'text-gray-700'}`}>Delivery Date</label>
                  <div className={`w-full px-3 py-2 border rounded-md flex items-center ${isDarkMode ? 'border-slate-500 bg-slate-700 text-slate-300' : 'border-gray-300 bg-gray-50 text-gray-700'}`}>
                    <span className="font-medium">{transferInfo.deliveryDate}</span>
                    <span className={`ml-2 text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>(Today&apos;s date)</span>
                  </div>
                  <p className={`text-xs mt-1 ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>Today&apos;s date is automatically set</p>
                </div>
              </div>
              <div className="flex justify-center space-x-3 mt-4">
                <button
                  onClick={() => setCurrentStep(1)}
                  className={`px-6 py-2 border rounded-md ${isDarkMode ? 'border-slate-500 hover:bg-slate-700 text-slate-300' : 'border-gray-300 hover:bg-gray-50 text-gray-700'}`}
                >
                  Back
                </button>
                <button
                  onClick={handleNextToProducts}
                  className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md"
                >
                  Next: Select Products
                </button>
              </div>
            </div>
          )}

          {/* Step 3: Transfer Products */}
          {currentStep === 3 && !showProductSelection && (
            <div>
              <h4 className={`text-xl font-semibold mb-6 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                <span className={`rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2 ${isDarkMode ? 'bg-slate-600 text-slate-200' : 'bg-gray-900 text-white'}`}>
                  3
                </span>
                Transfer Products*
              </h4>
              
              {/* Convenience Store Transfer Tips */}
              {storeData.destinationStore && storeData.destinationStore.toLowerCase().includes('convenience') && (
                <div className={`mb-6 p-4 border rounded-lg ${isDarkMode ? 'bg-green-900/20 border-green-700' : 'bg-green-50 border-green-200'}`}>
                  <div className="flex items-center mb-2">
                    <Package className={`h-5 w-5 mr-2 ${isDarkMode ? 'text-green-400' : 'text-green-600'}`} />
                    <span className={`font-medium ${isDarkMode ? 'text-green-200' : 'text-green-900'}`}>Convenience Store Transfer Tips</span>
                  </div>
                  <div className={`text-sm space-y-1 ${isDarkMode ? 'text-green-300' : 'text-green-700'}`}>
                    <p>• Select products that are suitable for retail sales</p>
                    <p>• Ensure quantities are appropriate for convenience store demand</p>
                    <p>• Products will be immediately available for POS transactions</p>
                    <p>• Check expiration dates for perishable items</p>
                  </div>
                </div>
              )}
              
              {selectedProducts.length === 0 ? (
                <div className="text-center py-12">
                  <div className="mb-4">
                    <Package className={`h-16 w-16 mx-auto ${isDarkMode ? 'text-slate-500' : 'text-gray-300'}`} />
                  </div>
                  <button
                    onClick={() => setShowProductSelection(true)}
                    className={`text-lg flex items-center justify-center space-x-2 mx-auto border-2 border-dashed px-6 py-3 rounded-lg ${isDarkMode ? 'text-blue-400 hover:text-blue-300 border-blue-500' : 'text-blue-600 hover:text-blue-800 border-blue-300'}`}
                  >
                    <Package className="h-5 w-5" />
                    <span>Select Products from {storeData.originalStore}</span>
                  </button>
                  
                  {/* Additional guidance for convenience store */}
                  {storeData.destinationStore && storeData.destinationStore.toLowerCase().includes('convenience') && (
                    <div className={`mt-4 text-sm ${isDarkMode ? 'text-slate-400' : 'text-gray-600'}`}>
                      <p>💡 Tip: Choose products that customers typically buy in convenience stores</p>
                    </div>
                  )}
                </div>
              ) : (
                <div>
                  {/* FIFO Information Banner */}
                  <div className={`mb-4 p-3 border rounded text-sm ${isDarkMode ? 'bg-orange-900/20 border-orange-700 text-orange-300' : 'bg-orange-50 border-orange-200 text-orange-700'}`}>
                    <div className="flex items-start">
                      <div className="flex-shrink-0 mr-2 mt-0.5">
                        <svg className={`h-4 w-4 ${isDarkMode ? 'text-orange-400' : 'text-orange-500'}`} fill="currentColor" viewBox="0 0 20 20">
                          <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                        </svg>
                      </div>
                      <div>
                        <div className={`font-medium mb-1 ${isDarkMode ? 'text-orange-200' : 'text-orange-800'}`}>🔄 Automatic FIFO Batch Switching System</div>
                        <div className={`text-xs ${isDarkMode ? 'text-orange-300' : 'text-orange-700'}`}>
                          <p>• <strong>⚡ Automatic Batch Switching:</strong> When oldest batch is depleted, system automatically uses next oldest batch</p>
                          <p>• <strong>🔄 FIFO Order:</strong> Oldest batches are consumed first (First-In-First-Out)</p>
                          <p>• <strong>🎯 Visual Indicators:</strong> Blue border = single batch, Orange border = multi-batch consumption</p>
                          <p>• <strong>📊 Real-time Feedback:</strong> Shows exactly which batches will be consumed</p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Transfer Instructions */}
                  <div className={`mb-4 p-3 border rounded text-sm ${isDarkMode ? 'bg-green-900/20 border-green-700 text-green-300' : 'bg-green-50 border-green-200 text-green-700'}`}>
                    <div className="flex items-start">
                      <Package className="h-4 w-4 mr-2 mt-0.5 flex-shrink-0" />
                      <div>
                        <div className={`font-medium mb-1 ${isDarkMode ? 'text-green-200' : 'text-green-900'}`}>📝 Transfer Quantity Instructions:</div>
                        <div className={`text-xs space-y-1 ${isDarkMode ? 'text-green-300' : 'text-green-700'}`}>
                          <div>• <strong>Enter any quantity</strong> you want to transfer - the system will allow any amount</div>
                          <div>• <strong>Red border</strong> = Quantity exceeds available stock (transfer may fail)</div>
                          <div>• <strong>Orange border</strong> = Will use multiple batches automatically (FIFO order)</div>
                          <div>• <strong>Blue border</strong> = Quantity fits within available stock</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Transfer Summary */}
                  <div className={`mb-4 p-3 border rounded text-sm ${isDarkMode ? 'bg-blue-900/20 border-blue-700 text-blue-300' : 'bg-blue-50 border-blue-200 text-blue-700'}`}>
                    <div className="flex items-center justify-between">
                      <div className="flex items-center">
                        <CheckCircle className="h-4 w-4 mr-2" />
                        <span>Products will be transferred to {storeData.destinationStore} for retail sales</span>
                      </div>
                      <div className="text-right">
                        <div className={`text-sm font-medium ${isDarkMode ? 'text-blue-200' : 'text-blue-900'}`}>
                          {selectedProducts.filter(p => p.transfer_quantity > 0).length} products selected for transfer
                        </div>
                        <div className={`text-xs ${isDarkMode ? 'text-blue-300' : 'text-blue-700'}`}>
                          Total quantity: {selectedProducts.reduce((sum, p) => sum + (p.transfer_quantity || 0), 0)} items
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <div className="overflow-x-auto max-h-96">
                    <table className={`border-collapse border text-sm ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ 
                      color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)',
                      tableLayout: 'fixed',
                      width: '100%',
                      minWidth: '1800px'
                    }}>
                      <thead className={`border-b sticky top-0 z-10 ${isDarkMode ? 'bg-slate-700 border-slate-600' : 'bg-gray-50 border-gray-200'}`}>
                        <tr>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '140px' }}>
                            Status
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '150px' }}>
                            Unit
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '120px' }}>
                            Transfer Qty
                          </th>
                          <th className={`border px-2 py-1 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '200px' }}>
                            Product
                          </th>
                          <th className={`border px-2 py-1 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '120px' }}>
                            Category
                          </th>
                          <th className={`border px-2 py-1 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '100px' }}>
                            Brand
                          </th>
                          <th className={`border px-2 py-1 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '140px' }}>
                            Barcode
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '120px' }}>
                            Oldest Batch
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '100px' }}>
                            Oldest Qty
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '120px' }}>
                            Total Available
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '180px' }}>
                            FIFO Batch Info
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '80px' }}>
                            SRP
                          </th>
                          <th className={`border px-2 py-1 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)', width: '150px' }}>
                            Action
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {selectedProducts.map((product) => (
                          <tr key={product.product_id} className={`hover:bg-gray-50 ${isDarkMode ? 'hover:bg-slate-700 bg-green-900/20' : 'bg-green-50'}`}>
                            <td className={`border px-2 py-1 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${isDarkMode ? 'bg-green-900/20 text-green-300' : 'bg-green-100 text-green-800'}`}>
                                <CheckCircle className="h-3 w-3 mr-1" />
                                Selected for Transfer
                              </span>
                            </td>
                            <td className={`border px-2 py-1 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <div className="flex flex-col items-center">
                                {productUnits[product.product_id] && productUnits[product.product_id].length > 0 ? (
                                  <>
                                    <div className={`text-xs mb-1 font-medium ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`}>
                                      {product.product_type === 'Medicine' ? '💊 Medicine Units' : '📦 Product Units'}
                                    </div>
                                    <select
                                      value={selectedUnits[product.product_id] || 'piece'}
                                      onChange={(e) => handleUnitChange(product.product_id, e.target.value)}
                                      className={`w-28 px-2 py-1 border rounded text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'bg-slate-700 border-slate-500 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                                    >
                                      {productUnits[product.product_id].map((unit) => (
                                        <option key={unit.unit_name} value={unit.unit_name}>
                                          {unit.unit_name.charAt(0).toUpperCase() + unit.unit_name.slice(1)} 
                                          {unit.unit_quantity > 1 && ` (${unit.unit_quantity})`}
                                        </option>
                                      ))}
                                    </select>
                                    <div className={`text-xs mt-1 ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                                      {(() => {
                                        const unit = productUnits[product.product_id].find(u => u.unit_name === selectedUnits[product.product_id]);
                                        return unit ? `1 ${unit.unit_name} = ${unit.unit_quantity} base unit${unit.unit_quantity > 1 ? 's' : ''}` : '';
                                      })()}
                                    </div>
                                  </>
                                ) : (
                                  <div className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                                    {product.product_type === 'Medicine' ? 'Tablet' : 'Piece'}
                                  </div>
                                )}
                              </div>
                            </td>
                            <td className={`border px-2 py-1 text-center align-top ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <div className="flex flex-col items-center">
                                <input
                                  type="number"
                                  min="0"
                                  placeholder="0"
                                  value={product.transfer_quantity}
                                  onChange={(e) => updateTransferQuantity(product.product_id, e.target.value)}
                                  className={`w-20 px-2 py-1 border rounded text-center focus:outline-none focus:ring-2 ${
                                    product.transfer_quantity > (product.total_quantity || product.available_for_transfer || 0)
                                      ? 'border-red-500 focus:ring-red-500' 
                                      : product.transfer_quantity > (product.oldest_batch_quantity || 0)
                                      ? 'border-orange-500 focus:ring-orange-500'
                                      : 'border-blue-300 focus:ring-blue-500'
                                  } ${isDarkMode ? 'bg-slate-700 text-white' : 'bg-white text-gray-900'}`}
                                />
                                {product.transfer_quantity > 0 && (
                                  <div className="text-xs mt-1">
                                    <div className={`font-medium ${
                                      product.transfer_quantity > (product.total_quantity || product.available_for_transfer || 0)
                                        ? 'text-red-600' 
                                        : product.transfer_quantity > (product.oldest_batch_quantity || 0)
                                        ? 'text-orange-600'
                                        : 'text-blue-600'
                                    }`}>
                                      {product.transfer_quantity} {selectedUnits[product.product_id] || 'units'}
                                    </div>
                                    <div className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                                      = {getBaseUnitQuantity(product.product_id, product.transfer_quantity)} base units
                                    </div>
                                  </div>
                                )}
                              </div>
                            </td>
                            <td className={`border px-2 py-1 align-top ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <div className="flex items-center space-x-3">
                                <img
                                  src={product.image || "/placeholder.svg?height=32&width=32"}
                                  alt={product.product_name}
                                  className="h-8 w-8 rounded object-cover flex-shrink-0"
                                />
                                <span className={`text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{product.product_name}</span>
                              </div>
                              {/* Batch Breakdown - Moved here to prevent column shifting */}
                              {product.transfer_quantity > 0 && (
                                <div className="mt-2 p-2 border rounded text-xs" style={{
                                  borderColor: isDarkMode ? '#374151' : '#d1d5db',
                                  backgroundColor: isDarkMode ? '#1f2937' : '#f9fafb'
                                }}>
                                  <div className="font-medium mb-1 flex items-center justify-between" style={{ color: isDarkMode ? '#e5e7eb' : '#374151' }}>
                                    <span>📦 Batch Info:</span>
                                    <span className="text-xs px-2 py-1 rounded" style={{ 
                                      backgroundColor: isDarkMode ? '#374151' : '#e5e7eb',
                                      color: isDarkMode ? '#9ca3af' : '#6b7280'
                                    }}>
                                      FIFO
                                    </span>
                                  </div>
                                  <div className="space-y-1">
                                    {getDetailedBatchBreakdown(product).map((batch, index) => (
                                      <div key={index} className="flex justify-between items-center p-1 rounded text-xs" style={{
                                        backgroundColor: batch.isOldest ? 
                                          (isDarkMode ? '#065f46' : '#d1fae5') : 
                                          (isDarkMode ? '#92400e' : '#fef3c7')
                                      }}>
                                        <span className="font-mono" style={{ 
                                          color: batch.isOldest ? 
                                            (isDarkMode ? '#10b981' : '#059669') : 
                                            (isDarkMode ? '#f59e0b' : '#d97706')
                                        }}>
                                          Batch {batch.batchNumber}
                                        </span>
                                        <span className="font-semibold" style={{ 
                                          color: batch.isOldest ? 
                                            (isDarkMode ? '#10b981' : '#059669') : 
                                            (isDarkMode ? '#f59e0b' : '#d97706')
                                        }}>
                                          {batch.quantity} units
                                        </span>
                                      </div>
                                    ))}
                                  </div>
                                </div>
                              )}
                            </td>
                            <td className={`border px-2 py-1 text-sm align-top ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.category}</td>
                            <td className={`border px-2 py-1 text-sm align-top ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.brand || "-"}</td>
                            <td className={`border px-2 py-1 text-sm font-mono align-top ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.barcode}</td>
                            <td className={`border px-2 py-1 text-sm text-center font-semibold align-top ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>
                              {product.oldest_batch_reference || product.batch_reference || 'N/A'}
                            </td>
                            <td className={`border px-2 py-1 text-sm text-center font-semibold align-top ${isDarkMode ? 'border-slate-600 text-orange-400' : 'border-gray-300 text-orange-600'}`}>
                              {product.oldest_batch_quantity || 0}
                            </td>
                            <td className={`border px-2 py-1 text-sm text-center font-semibold align-top ${isDarkMode ? 'border-slate-600 text-green-400' : 'border-gray-300 text-green-600'}`}>
                              {product.total_quantity || 0}
                            </td>
                            <td className={`border px-2 py-1 text-sm text-center align-top ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <FifoStockInfo 
                                product={product} 
                                sourceLocationId={locations.find(loc => loc.location_name.toLowerCase().includes('warehouse'))?.location_id || 2}
                                transferQuantity={product.transfer_quantity}
                                showFullDetails={false}
                              />
                            </td>
                            <td className={`border px-2 py-1 text-sm text-center align-top ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>
                              ₱{Number.parseFloat(product.srp || 0).toFixed(2)}
                            </td>
                            <td className={`border px-2 py-1 text-center align-top ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <div className="flex items-center justify-center">
                                {/* Enhanced Remove Button */}
                                <button
                                  onClick={() => removeProduct(product.product_id)}
                                  className={`flex items-center gap-1 px-2 py-1 rounded transition-all duration-200 ${
                                    isDarkMode 
                                      ? 'text-red-400 hover:text-red-300 hover:bg-red-900/20' 
                                      : 'text-red-600 hover:text-red-700 hover:bg-red-50'
                                  }`}
                                  title="Remove product from transfer"
                                >
                                  <X className="h-4 w-4" />
                                  <span className="text-xs font-medium">Remove</span>
                                </button>
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  <div className="flex justify-between">
                    <button
                      onClick={() => setShowProductSelection(true)}
                      className={`text-sm border px-4 py-2 rounded ${isDarkMode ? 'text-blue-400 hover:text-blue-300 border-blue-500' : 'text-blue-600 hover:text-blue-800 border-blue-300'}`}
                    >
                      <Package className="h-4 w-4 inline mr-2" />
                      Select Products from {storeData.originalStore}
                    </button>
                    <div className="flex space-x-4">
                      <button
                        onClick={() => setCurrentStep(2)}
                        className={`px-6 py-2 border rounded-md ${isDarkMode ? 'border-slate-500 hover:bg-slate-700 text-slate-300' : 'border-gray-300 hover:bg-gray-50 text-gray-700'}`}
                      >
                        Back
                      </button>
                      <button
                        onClick={handleTransferSubmit}
                        disabled={loading || selectedProducts.filter((p) => p.transfer_quantity > 0).length === 0}
                        className="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
                      >
                        {loading ? "Creating..." : "Create Transfer"}
                      </button>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Product Selection View */}
          {currentStep === 3 && showProductSelection && (
            <div>
              <h4 className={`text-xl font-semibold mb-6 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                <span className={`rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2 ${isDarkMode ? 'bg-slate-600 text-slate-200' : 'bg-gray-900 text-white'}`}>
                  3
                </span>
                Select Transfer Products from {storeData.originalStore}
              </h4>
              
              {/* Warehouse Products Status */}
              <div className={`mb-6 p-4 border rounded-lg ${isDarkMode ? 'bg-blue-900/20 border-blue-700' : 'bg-blue-50 border-blue-200'}`}>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className={`text-lg ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`}>📦</div>
                    <div>
                      <h5 className={`font-medium ${isDarkMode ? 'text-blue-200' : 'text-blue-900'}`}>Warehouse Products Status</h5>
                      <p className={`text-sm ${isDarkMode ? 'text-blue-300' : 'text-blue-700'}`}>
                        {loadingProducts 
                          ? "🔄 Loading warehouse products..."
                          : availableProducts.length > 0 
                            ? `${availableProducts.length} products available for transfer`
                            : "No warehouse products loaded"
                        }
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                  </div>
                </div>
              </div>
              {/* Search and Filters */}
              <div className="flex items-center gap-4 mb-6">
                <div className="relative flex-1">
                  <Search className={`absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 ${isDarkMode ? 'text-slate-400' : 'text-gray-400'}`} />
                  <input
                    type="text"
                    placeholder="Search by Product Name/SKU/Barcode"
                    value={productSearchTerm}
                    onChange={(e) => setProductSearchTerm(e.target.value)}
                    className={`pl-10 pr-4 py-2 w-full border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'border-slate-500 bg-slate-700 text-white placeholder-slate-300' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                  />
                </div>
                <select
                  value={selectedCategory}
                  onChange={(e) => setSelectedCategory(e.target.value)}
                  className={`px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'border-slate-500 bg-slate-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                >
                  <option value="All Product Category">All Product Category</option>
                  {categories.map((category) => (
                    <option key={category} value={category}>
                      {typeof category === 'string' ? category : (category?.category_name || 'Unknown')}
                    </option>
                  ))}
                </select>
                <select
                  value={selectedSupplier}
                  onChange={(e) => setSelectedSupplier(e.target.value)}
                  className={`px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDarkMode ? 'border-slate-500 bg-slate-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                >
                  <option value="All Suppliers">All Suppliers</option>
                  {suppliers.map((supplier) => (
                    <option key={supplier} value={supplier}>
                      {supplier}
                    </option>
                  ))}
                </select>
              </div>
              {/* Products Table */}
              <div className="overflow-x-auto max-h-96 mb-4">
                <table className={`w-full min-w-max border-collapse border ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                  <thead className={`border-b sticky top-0 z-10 ${isDarkMode ? 'bg-slate-700 border-slate-600' : 'bg-gray-50 border-gray-200'}`}>
                    <tr>
                      <th className={`border px-4 py-2 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        <input
                          type="checkbox"
                          onChange={(e) => {
                            if (e.target.checked) {
                              setCheckedProducts(filteredProducts.map((p) => p.product_id))
                            } else {
                              setCheckedProducts([])
                            }
                          }}
                          checked={checkedProducts.length === filteredProducts.length && filteredProducts.length > 0}
                        />
                      </th>
                      <th className={`border px-4 py-2 text-center text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Status
                      </th>
                      <th className={`border px-4 py-2 text-left text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Product
                      </th>
                      <th className={`border px-4 py-2 text-left text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Category
                      </th>
                      <th className={`border px-4 py-2 text-left text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Brand
                      </th>
                      <th className={`border px-4 py-2 text-left text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Supplier
                      </th>
                      <th className={`border px-4 py-2 text-left text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Barcode
                      </th>
                      
                      <th className={`border px-4 py-2 text-center text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Total Qty
                      </th>
                      <th className={`border px-4 py-2 text-center text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        Expiration Status
                      </th>
                      <th className={`border px-4 py-2 text-center text-sm font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                        SRP
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {loadingProducts ? (
                      <tr>
                        <td colSpan={10} className="px-6 py-8 text-center">
                          <div className="flex flex-col items-center space-y-3">
                            <div className={`h-12 w-12 rounded-full flex items-center justify-center ${isDarkMode ? 'bg-blue-900/20' : 'bg-blue-100'}`}>
                              <span className={`text-xl animate-spin ${isDarkMode ? 'text-blue-400' : 'text-blue-600'}`}>🔄</span>
                            </div>
                            <div className={isDarkMode ? 'text-slate-400' : 'text-gray-500'}>
                              <p className="text-lg font-medium">Loading warehouse products...</p>
                              <p className="text-sm">Please wait while we fetch the latest inventory data</p>
                            </div>
                          </div>
                        </td>
                      </tr>
                    ) : filteredProducts.length > 0 ? (
                      filteredProducts.map((product) => {
                        const isTransferred = selectedProducts.some(sp => sp.product_id === product.product_id);
                        return (
                          <tr key={product.product_id} className={`${isDarkMode ? 'hover:bg-slate-700' : 'hover:bg-gray-50'} ${isTransferred ? (isDarkMode ? 'bg-green-900/20' : 'bg-green-50') : ''}`}>
                            <td className={`border px-4 py-2 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <input
                                type="checkbox"
                                checked={checkedProducts.includes(product.product_id)}
                                onChange={(e) => handleProductCheck(product.product_id, e.target.checked)}
                                disabled={isTransferred}
                              />
                            </td>
                            <td className={`border px-4 py-2 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              {isTransferred ? (
                                <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${isDarkMode ? 'bg-green-900/20 text-green-300' : 'bg-green-100 text-green-800'}`}>
                                  <CheckCircle className="h-3 w-3 mr-1" />
                                  Transferred
                                </span>
                              ) : (
                                <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${isDarkMode ? 'bg-slate-700 text-slate-300' : 'bg-gray-100 text-gray-800'}`}>
                                  Available
                                </span>
                              )}
                            </td>
                            <td className={`border px-4 py-2 ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              <span className={`text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{product.product_name}</span>
                            </td>
                            <td className={`border px-4 py-2 text-sm ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.category}</td>
                            <td className={`border px-4 py-2 text-sm ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.brand || "-"}</td>
                            <td className={`border px-4 py-2 text-sm ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.supplier_name || "-"}</td>
                            <td className={`border px-4 py-2 text-sm font-mono ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.barcode}</td>

                            <td className={`border px-4 py-2 text-sm text-center font-semibold ${isDarkMode ? 'border-slate-600 text-green-400' : 'border-gray-300 text-green-600'}`}>
                              <div>
                                <div className="font-bold text-lg">{product.total_quantity || 0}</div>
                                <div className={`text-xs font-medium ${isDarkMode ? 'text-green-400' : 'text-green-600'}`}>total all batches</div>
                              </div>
                            </td>
                            <td className={`border px-4 py-2 text-sm text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                              {(() => {
                                const expiryInfo = checkProductExpiration(product);
                                if (!expiryInfo) return (
                                  <span className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>No expiration</span>
                                );
                                
                                return (
                                  <div className="flex flex-col items-center">
                                    <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getExpirationStatusColor(expiryInfo)}`}>
                                      {expiryInfo.isExpired && <AlertCircle className="h-3 w-3 mr-1" />}
                                      {expiryInfo.isCritical && <AlertTriangle className="h-3 w-3 mr-1" />}
                                      {expiryInfo.isExpiringSoon && !expiryInfo.isCritical && <Clock className="h-3 w-3 mr-1" />}
                                      {getExpirationStatusText(expiryInfo)}
                                    </span>
                                    <div className={`text-xs mt-1 ${isDarkMode ? 'text-slate-400' : 'text-gray-600'}`}>
                                      {expiryInfo.isExpired ? 
                                        `${Math.abs(expiryInfo.daysUntilExpiry)} days ago` :
                                        `${expiryInfo.daysUntilExpiry} days`
                                      }
                                    </div>
                                    {product.oldest_batch_expiration && (
                                      <div className={`text-xs ${isDarkMode ? 'text-slate-500' : 'text-gray-500'}`}>
                                        {new Date(product.oldest_batch_expiration).toLocaleDateString()}
                                      </div>
                                    )}
                                  </div>
                                );
                              })()}
                            </td>
                            <td className={`border px-4 py-2 text-sm text-center ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>
                              ₱{Number.parseFloat(product.srp || 0).toFixed(2)}
                            </td>
                          </tr>
                        );
                      })
                    ) : (
                      <tr>
                        <td colSpan={10} className="px-6 py-8 text-center">
                          <div className="flex flex-col items-center space-y-3">
                            <div className={`h-12 w-12 rounded-full flex items-center justify-center ${isDarkMode ? 'bg-slate-700' : 'bg-gray-100'}`}>
                              <span className={`text-xl ${isDarkMode ? 'text-slate-400' : 'text-gray-600'}`}>📦</span>
                            </div>
                            <div className={isDarkMode ? 'text-slate-400' : 'text-gray-500'}>
                              <p className="text-lg font-medium">No warehouse products found</p>
                              <p className="text-sm">Click &ldquo;Refresh&rdquo; to load warehouse products or check if products exist in warehouse</p>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
              {/* Action Buttons */}
              <div className="flex justify-between items-center">
                <button className={`text-sm border px-4 py-2 rounded ${isDarkMode ? 'text-blue-400 hover:text-blue-300 border-blue-500' : 'text-blue-600 hover:text-blue-800 border-blue-300'}`}>
                  View Selected Products({checkedProducts.length}/500)
                </button>
                <div className="flex space-x-4">
                  <button
                    onClick={() => setShowProductSelection(false)}
                    className={`px-6 py-2 border rounded-md ${isDarkMode ? 'border-slate-500 hover:bg-slate-700 text-slate-300' : 'border-gray-300 hover:bg-gray-50 text-gray-700'}`}
                  >
                    Back
                  </button>
                  <button
                    onClick={handleSelectProducts}
                    disabled={checkedProducts.length === 0}
                    className="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Select ({checkedProducts.length} products)
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Expiration Alert Dialog */}
      {showExpirationAlert && (
        <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
          <div className={`rounded-lg shadow-xl p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto border-2 ${isDarkMode ? 'bg-slate-800 border-red-500' : 'bg-white border-red-400'}`}>
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-3">
                <div className={`h-10 w-10 rounded-full flex items-center justify-center ${isDarkMode ? 'bg-red-900/20' : 'bg-red-100'}`}>
                  <AlertTriangle className={`h-6 w-6 ${isDarkMode ? 'text-red-400' : 'text-red-600'}`} />
                </div>
                <div>
                  <h3 className={`text-xl font-semibold ${isDarkMode ? 'text-red-200' : 'text-red-900'}`}>🚨 Expiration Alert</h3>
                  <p className={`text-sm ${isDarkMode ? 'text-red-300' : 'text-red-700'}`}>
                    {expiringProducts.length} product(s) require immediate attention
                  </p>
                </div>
              </div>
              <button
                onClick={() => setShowExpirationAlert(false)}
                className={`${isDarkMode ? 'text-slate-400 hover:text-slate-200' : 'text-gray-400 hover:text-gray-600'}`}
              >
                <X className="h-6 w-6" />
              </button>
            </div>

            {/* Alert Summary */}
            <div className={`mb-4 p-4 border rounded-lg ${isDarkMode ? 'bg-red-900/20 border-red-700' : 'bg-red-50 border-red-200'}`}>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div className="text-center">
                  <div className={`text-2xl font-bold ${isDarkMode ? 'text-red-400' : 'text-red-600'}`}>
                    {expiringProducts.filter(p => p.expiryInfo.isExpired).length}
                  </div>
                  <div className={isDarkMode ? 'text-red-300' : 'text-red-700'}>EXPIRED</div>
                </div>
                <div className="text-center">
                  <div className={`text-2xl font-bold ${isDarkMode ? 'text-orange-400' : 'text-orange-600'}`}>
                    {expiringProducts.filter(p => p.expiryInfo.isCritical).length}
                  </div>
                  <div className={isDarkMode ? 'text-orange-300' : 'text-orange-700'}>CRITICAL (≤7 days)</div>
                </div>
                <div className="text-center">
                  <div className={`text-2xl font-bold ${isDarkMode ? 'text-yellow-400' : 'text-yellow-600'}`}>
                    {expiringProducts.filter(p => p.expiryInfo.isExpiringSoon && !p.expiryInfo.isCritical).length}
                  </div>
                  <div className={isDarkMode ? 'text-yellow-300' : 'text-yellow-700'}>WARNING (≤10 days)</div>
                </div>
              </div>
            </div>

            {/* Products Table */}
            <div className="overflow-x-auto max-h-96">
              <table className={`w-full min-w-max border-collapse border ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)' }}>
                <thead className={`border-b sticky top-0 z-10 ${isDarkMode ? 'bg-slate-700 border-slate-600' : 'bg-gray-50 border-gray-200'}`}>
                  <tr>
                    <th className={`border px-3 py-2 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Status
                    </th>
                    <th className={`border px-3 py-2 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Product
                    </th>
                    <th className={`border px-3 py-2 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Category
                    </th>
                    <th className={`border px-3 py-2 text-left text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Batch Reference
                    </th>
                    <th className={`border px-3 py-2 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Expiration Date
                    </th>
                    <th className={`border px-3 py-2 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Days Until Expiry
                    </th>
                    <th className={`border px-3 py-2 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Available Qty
                    </th>
                    <th className={`border px-3 py-2 text-center text-xs font-medium ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`} style={{ color: 'var(--inventory-text-primary)' }}>
                      Action
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {expiringProducts.map((product, index) => (
                    <tr key={index} className={`${isDarkMode ? 'hover:bg-slate-700' : 'hover:bg-gray-50'}`}>
                      <td className={`border px-3 py-2 ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                        <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getExpirationStatusColor(product.expiryInfo)}`}>
                          {product.expiryInfo.isExpired && <AlertCircle className="h-3 w-3 mr-1" />}
                          {product.expiryInfo.isCritical && <AlertTriangle className="h-3 w-3 mr-1" />}
                          {product.expiryInfo.isExpiringSoon && !product.expiryInfo.isCritical && <Clock className="h-3 w-3 mr-1" />}
                          {getExpirationStatusText(product.expiryInfo)}
                        </span>
                      </td>
                      <td className={`border px-3 py-2 ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                        <div className="flex flex-col">
                          <span className={`text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{product.product_name}</span>
                          <span className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>ID: {product.product_id}</span>
                        </div>
                      </td>
                      <td className={`border px-3 py-2 text-sm ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>{product.category}</td>
                      <td className={`border px-3 py-2 text-sm font-mono ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>
                        {product.oldest_batch_reference || 'N/A'}
                      </td>
                      <td className={`border px-3 py-2 text-center text-sm ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>
                        {product.oldest_batch_expiration ? 
                          new Date(product.oldest_batch_expiration).toLocaleDateString() : 'N/A'
                        }
                      </td>
                      <td className={`border px-3 py-2 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                        <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded ${
                          product.expiryInfo.isExpired ? (isDarkMode ? 'bg-red-900/20 text-red-300' : 'bg-red-100 text-red-800') :
                          product.expiryInfo.isCritical ? (isDarkMode ? 'bg-red-900/20 text-red-300' : 'bg-red-100 text-red-800') :
                          (isDarkMode ? 'bg-yellow-900/20 text-yellow-300' : 'bg-yellow-100 text-yellow-800')
                        }`}>
                          {product.expiryInfo.isExpired ? 
                            `${Math.abs(product.expiryInfo.daysUntilExpiry)} days ago` :
                            `${product.expiryInfo.daysUntilExpiry} days`
                          }
                        </span>
                      </td>
                      <td className={`border px-3 py-2 text-center text-sm font-semibold ${isDarkMode ? 'border-slate-600 text-white' : 'border-gray-300 text-gray-900'}`}>
                        {product.oldest_batch_quantity || 0}
                      </td>
                      <td className={`border px-3 py-2 text-center ${isDarkMode ? 'border-slate-600' : 'border-gray-300'}`}>
                        <div className="flex flex-col gap-2">
                          {/* Add to Transfer Button */}
                          <div className={`relative group ${isDarkMode ? 'bg-slate-800' : 'bg-white'} rounded-lg border shadow-sm hover:shadow-md transition-all duration-200 ${isDarkMode ? 'border-slate-600 hover:border-blue-500' : 'border-gray-200 hover:border-blue-300'}`}>
                            <button
                              onClick={() => {
                                // Add to transfer selection if not already selected
                                if (!selectedProducts.some(sp => sp.product_id === product.product_id)) {
                                  setSelectedProducts(prev => [...prev, {
                                    ...product,
                                    transfer_quantity: 0
                                  }])
                                  toast.success(`✅ Added ${product.product_name} to transfer selection`)
                                } else {
                                  toast.info(`ℹ️ ${product.product_name} is already in transfer selection`)
                                }
                              }}
                              className={`flex items-center gap-2 px-3 py-2 rounded-lg transition-all duration-200 w-full ${
                                isDarkMode 
                                  ? 'text-blue-400 hover:text-blue-300 hover:bg-blue-900/20' 
                                  : 'text-blue-600 hover:text-blue-700 hover:bg-blue-50'
                              }`}
                              title="Add to transfer selection"
                            >
                              <div className={`p-1 rounded-md ${isDarkMode ? 'bg-blue-900/30' : 'bg-blue-100'}`}>
                                <Package className="h-4 w-4" />
                              </div>
                              <div className="flex flex-col items-start">
                                <span className="text-sm font-medium">Add to Transfer</span>
                                <span className={`text-xs ${isDarkMode ? 'text-slate-400' : 'text-gray-500'}`}>
                                  Priority item
                                </span>
                              </div>
                            </button>
                          </div>
                          
                          {/* View Details Button */}
                          <div className={`relative group ${isDarkMode ? 'bg-slate-800' : 'bg-white'} rounded-lg border shadow-sm hover:shadow-md transition-all duration-200 ${isDarkMode ? 'border-slate-600 hover:border-gray-500' : 'border-gray-200 hover:border-gray-400'}`}>
                            <button
                              onClick={() => {
                                // Show detailed product info
                                toast.info(`📦 ${product.product_name} - Batch: ${product.oldest_batch_reference}, Expires: ${new Date(product.oldest_batch_expiration).toLocaleDateString()}`)
                              }}
                              className={`flex items-center gap-2 px-3 py-2 rounded-lg transition-all duration-200 w-full ${
                                isDarkMode 
                                  ? 'text-slate-400 hover:text-slate-200 hover:bg-slate-700/50' 
                                  : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50'
                              }`}
                              title="View product details"
                            >
                              <div className={`p-1 rounded-md ${isDarkMode ? 'bg-slate-700/50' : 'bg-gray-100'}`}>
                                <Eye className="h-4 w-4" />
                              </div>
                              <div className="flex flex-col items-start">
                                <span className="text-sm font-medium">View Details</span>
                                <span className={`text-xs ${isDarkMode ? 'text-slate-500' : 'text-gray-500'}`}>
                                  Product info
                                </span>
                              </div>
                            </button>
                          </div>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Action Buttons */}
            <div className={`flex justify-between items-center mt-6 pt-4 border-t ${isDarkMode ? 'border-slate-600' : 'border-gray-200'}`}>
              <div className={`text-sm ${isDarkMode ? 'text-slate-300' : 'text-gray-600'}`}>
                <p>💡 <strong>Recommendation:</strong> Transfer expiring products to retail locations for immediate sale</p>
                <p>⚡ <strong>Priority:</strong> Handle expired and critical products first</p>
              </div>
              <div className="flex gap-3">
                <button
                  onClick={() => {
                    // Add all critical products to transfer
                    const criticalProducts = expiringProducts.filter(p => p.expiryInfo.isCritical || p.expiryInfo.isExpired)
                    const newProducts = criticalProducts.filter(p => !selectedProducts.some(sp => sp.product_id === p.product_id))
                    if (newProducts.length > 0) {
                      setSelectedProducts(prev => [...prev, ...newProducts.map(p => ({ ...p, transfer_quantity: 0 }))])
                      toast.success(`✅ Added ${newProducts.length} critical products to transfer selection`)
                    } else {
                      toast.info("ℹ️ All critical products are already in transfer selection")
                    }
                  }}
                  className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm"
                >
                  Add Critical Products
                </button>
                <button
                  onClick={() => setShowExpirationAlert(false)}
                  className="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-md text-sm"
                >
                  Close Alert
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

        {/* Quantity History Modal */}
        {showQuantityHistoryModal && selectedProductForHistory && (
          <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50">
            <div className="backdrop-blur-md rounded-xl shadow-2xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto border"
                 style={{
                   backgroundColor: theme.bg.card,
                   borderColor: theme.border.default
                 }}>
              <div className="flex items-center justify-between p-6 border-b" style={{ borderColor: theme.border.default }}>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>
                  Batch Details - {selectedProductForHistory.product_name}
                </h3>
                <div className="flex items-center gap-2">
                  <button onClick={closeQuantityHistoryModal}
                          style={{ color: theme.text.muted }}
                          onMouseEnter={(e) => e.target.style.color = theme.text.secondary}
                          onMouseLeave={(e) => e.target.style.color = theme.text.muted}>
                    <X className="h-6 w-6" />
                  </button>
                </div>
              </div>

              <div className="p-6">
                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                  <div className="p-4 rounded-lg border" style={{ borderColor: theme.border.light, backgroundColor: theme.bg.secondary }}>
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Product Name</p>
                        <p className="text-lg font-semibold" style={{ color: theme.text.primary }}>{selectedProductForHistory.product_name}</p>
                      </div>
                      <Package className="h-8 w-8" style={{ color: theme.colors.primary }} />
                    </div>
                  </div>
                  
                  <div className="p-4 rounded-lg border" style={{ borderColor: theme.border.light, backgroundColor: theme.bg.secondary }}>
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Product ID</p>
                        <p className="text-lg font-semibold" style={{ color: theme.text.primary }}>#{selectedProductForHistory.product_id}</p>
                      </div>
                      <CheckCircle className="h-8 w-8" style={{ color: theme.colors.success }} />
                    </div>
                  </div>
                  
                  <div className="p-4 rounded-lg border" style={{ borderColor: theme.border.light, backgroundColor: theme.bg.secondary }}>
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Current FIFO Batches</p>
                        <p className="text-lg font-semibold" style={{ color: theme.text.primary }}>{fifoStockData.length} batches</p>
                      </div>
                      <Clock className="h-8 w-8" style={{ color: theme.colors.warning }} />
                    </div>
                  </div>
                </div>

                {/* Transfer Details Section */}
                <div className="mb-6">
                  <h4 className="font-semibold mb-3" style={{ color: theme.text.primary }}>Transfer Details</h4>
                  <p className="text-sm mb-4" style={{ color: theme.text.secondary }}>Showing all transfers for this product</p>
                  <div id="transferHistoryTable" className="min-h-[200px]">
                    <div className="text-center py-8">
                      <Package className="h-12 w-12 mx-auto mb-4" style={{ color: theme.text.muted }} />
                      <p style={{ color: theme.text.secondary }}>Loading transfer history...</p>
                    </div>
                  </div>
                </div>

                {/* Current FIFO Batches Table */}
                <div>
                  <h4 className="font-semibold mb-3" style={{ color: theme.text.primary }}>Current FIFO Batches</h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm border-collapse border" style={{ borderColor: theme.border.light }}>
                      <thead>
                        <tr style={{ backgroundColor: theme.bg.secondary }}>
                          <th className="px-3 py-2 text-left font-medium border" style={{ borderColor: theme.border.light, color: theme.text.secondary }}>Batch #</th>
                          <th className="px-3 py-2 text-left font-medium border" style={{ borderColor: theme.border.light, color: theme.text.secondary }}>Reference</th>
                          <th className="px-3 py-2 text-center font-medium border" style={{ borderColor: theme.border.light, color: theme.text.secondary }}>Quantity</th>
                          <th className="px-3 py-2 text-center font-medium border" style={{ borderColor: theme.border.light, color: theme.text.secondary }}>Unit Cost</th>
                          <th className="px-3 py-2 text-center font-medium border" style={{ borderColor: theme.border.light, color: theme.text.secondary }}>Expiry Date</th>
                          <th className="px-3 py-2 text-center font-medium border" style={{ borderColor: theme.border.light, color: theme.text.secondary }}>Days Left</th>
                        </tr>
                      </thead>
                      <tbody>
                        {fifoStockData.length > 0 ? (
                          fifoStockData.map((batch, index) => {
                            const expiryDate = batch.expiration_date ? new Date(batch.expiration_date) : null;
                            const today = new Date();
                            const daysLeft = expiryDate ? Math.ceil((expiryDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24)) : null;
                            
                            return (
                              <tr key={index} style={{ borderBottom: `1px solid ${theme.border.light}` }}>
                                <td className="px-3 py-2 font-mono border" style={{ borderColor: theme.border.light, color: theme.text.primary }}>
                                  {batch.batch_number || batch.batch_id || `B-${transfer.transfer_id}-${index + 1}`}
                                </td>
                                <td className="px-3 py-2 font-mono text-xs border" style={{ borderColor: theme.border.light, color: theme.text.primary }}>
                                  {batch.batch_reference}
                                </td>
                                <td className="px-3 py-2 text-center font-medium border" style={{ borderColor: theme.border.light, color: theme.colors.primary }}>
                                  {batch.available_quantity} units
                                </td>
                                <td className="px-3 py-2 text-center border" style={{ borderColor: theme.border.light, color: theme.text.primary }}>
                                  ₱{Number.parseFloat(batch.unit_cost || 0).toFixed(2)}
                                </td>
                                <td className="px-3 py-2 text-center border" style={{ borderColor: theme.border.light, color: theme.text.primary }}>
                                  {expiryDate ? expiryDate.toLocaleDateString() : 'N/A'}
                                </td>
                                <td className="px-3 py-2 text-center border" style={{ borderColor: theme.border.light, color: theme.text.primary }}>
                                  {daysLeft !== null ? (
                                    <span className={`px-2 py-1 rounded text-xs font-medium ${
                                      daysLeft < 0 ? 'bg-red-100 text-red-800' :
                                      daysLeft <= 7 ? 'bg-yellow-100 text-yellow-800' :
                                      'bg-green-100 text-green-800'
                                    }`}>
                                      {daysLeft < 0 ? `${Math.abs(daysLeft)} days ago` : `${daysLeft} days`}
                                    </span>
                                  ) : 'N/A'}
                                </td>
                              </tr>
                            );
                          })
                        ) : (
                          <tr>
                            <td colSpan={6} className="px-3 py-8 text-center border" style={{ borderColor: theme.border.light, color: theme.text.muted }}>
                              No FIFO batches available
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

      </div>
    )
  }


export default InventoryTransfer;