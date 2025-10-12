import React, { useState, useEffect } from "react";
import axios from "axios";
import { toast } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";
import { useTheme } from "./ThemeContext";
import { useSettings } from "./SettingsContext";
import NotificationSystem from "./NotificationSystem";

import {
  ChevronUp,
  ChevronDown,
  Plus,
  X,
  Search,
  MapPin,
  Scan,
  Camera,
  Package,
  User,
  Truck,
  DollarSign,
  Edit,
  Archive,
  RefreshCw,
  Trash2,
  Bell,
  BellRing,
  Clock,
  AlertCircle,
  AlertTriangle,
  CheckCircle,
  Calendar,
} from "lucide-react";

// API Configuration

// Safe toast wrapper function
let errorToastShown = false;
function safeToast(type, message) {
  try {
    if (type === 'success') {
      toast.success(message);
    } else if (type === 'error') {
      if (!errorToastShown) {
        toast.warning(message); // Use warning for non-critical errors
        errorToastShown = true;
      }
    } else if (type === 'warning') {
      toast.warning(message);
    } else if (type === 'info') {
      toast.info(message);
    }
  } catch (error) {
    // `${type.toUpperCase()} notification: ${message}`);
  }
}

function resetErrorToast() {
  errorToastShown = false;
}


import { getApiEndpointForAction } from '../lib/apiHandler';
import apiHandler from '../lib/apiHandler';
import { getApiUrl } from '../lib/apiConfig';

// API function - Updated to use centralized API handler
async function handleApiCall(action, data = {}) {
  try {
    const endpoint = getApiEndpointForAction(action);
    console.log(`🔗 Making API call: ${action} -> ${endpoint}`, data);
    console.log(`🔗 Full endpoint URL: ${process.env.NEXT_PUBLIC_API_BASE_URL}/${endpoint}`);
    
    const response = await apiHandler.callAPI(endpoint, action, data);
    
    console.log(`📥 API response for ${action}:`, response);
    console.log(`📥 API response type:`, typeof response);
    console.log(`📥 API response keys:`, response ? Object.keys(response) : 'null');
    
    if (response && typeof response === "object") {
      // Don't show error toast here - let the calling function handle it
      // This prevents false error messages for informational responses
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
    console.error("❌ Error details:", {
      name: error.name,
      message: error.message,
      stack: error.stack
    });
    return {
      success: false,
      message: error.message,
      error: "REQUEST_ERROR",
    };
  }
}

// New function to check if product name exists
async function checkProductNameExists(productName) {
  try {
    console.log("🔍 Calling checkProductNameExists with product name:", productName);
    const response = await handleApiCall("check_product_name", { product_name: productName });
    console.log("🔍 checkProductNameExists response:", response);
    return response;
  } catch (error) {
    console.error("❌ Error in checkProductNameExists:", error);
    safeToast("error", "Error checking product name:", error);
    return { success: false, error: error.message };
  }
}

// New function to check if barcode exists
async function checkBarcodeExists(barcode) {
  try {
    console.log("🔍 Calling checkBarcodeExists with barcode:", barcode);
    const response = await handleApiCall("check_barcode", { barcode: barcode });
    console.log("🔍 checkBarcodeExists RAW response:", JSON.stringify(response, null, 2));
    console.log("🔍 checkBarcodeExists response.success:", response.success);
    console.log("🔍 checkBarcodeExists response.found:", response.found);
    console.log("🔍 checkBarcodeExists response.product:", response.product);
    return response;
  } catch (error) {
    console.error("❌ Error in checkBarcodeExists:", error);
    safeToast("error", "Error checking barcode:", error);
    return { success: false, error: error.message };
  }
}

// New function to update product stock with FIFO tracking
// NOTE: tbl_product no longer stores quantity or srp - these are ONLY in tbl_fifo_stock
// This function creates a NEW FIFO batch entry with the quantity and SRP
async function updateProductStock(productId, newQuantity, batchReference = "", expirationDate = null, unitCost = 0, newSrp = null, entryBy = "admin") {
  try {
    console.log("🔄 Creating new FIFO batch for product:", {
      productId,
      newQuantity,
      batchReference,
      expirationDate,
      unitCost,
      newSrp,
      entryBy
    });
    
    // Validate input data
    if (!productId || productId <= 0) {
      console.error("❌ Invalid product ID:", productId);
      return { success: false, message: "Invalid product ID" };
    }
    
    if (!newQuantity || newQuantity <= 0) {
      console.error("❌ Invalid quantity:", newQuantity);
      return { success: false, message: "Invalid quantity" };
    }
    
    // Validate SRP - REQUIRED for FIFO tracking
    if (!newSrp || newSrp <= 0) {
      console.error("❌ Invalid SRP:", newSrp);
      return { success: false, message: "SRP is required for FIFO stock tracking" };
    }
    
    // Validate expiration date - REQUIRED for FIFO tracking
    if (!expirationDate) {
      console.error("❌ Missing expiration date");
      return { success: false, message: "Expiration date is required for FIFO stock tracking" };
    }
    
    const apiData = { 
      product_id: productId, 
      new_quantity: newQuantity,
      batch_reference: batchReference,
      expiration_date: expirationDate,
      unit_cost: unitCost || newSrp, // Use newSrp as fallback for unit_cost
      new_srp: newSrp, // This is the SRP for the FIFO batch
      entry_by: entryBy
    };
    
    console.log("📤 Sending API data for FIFO batch creation:", apiData);
    
    const response = await handleApiCall("update_product_stock", apiData);
    
    console.log("📊 FIFO batch creation response:", response);
    
    // Log the stock update activity with user context
    if (response.success) {
      try {
        const userData = JSON.parse(sessionStorage.getItem('user_data') || '{}');
        await fetch(getApiUrl('backend.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'log_activity',
            activity_type: 'WAREHOUSE_FIFO_BATCH_CREATED',
            description: `New FIFO batch created: Product ID ${productId}, Quantity: ${newQuantity}, Batch: ${batchReference || 'N/A'}, SRP: ₱${newSrp}`,
            table_name: 'tbl_fifo_stock',
            record_id: productId,
            user_id: userData.user_id || userData.emp_id,
            username: userData.username,
            role: userData.role,
          }),
        });
        console.log("✅ Activity logged successfully");
      } catch (error) {
        console.warn("⚠️ Failed to log activity:", error);
      }
    } else {
      console.error("❌ FIFO batch creation failed:", response.message);
    }
    
    return response;
  } catch (error) {
    console.error("❌ Error creating FIFO batch:", error);
    
    // Log the error
    try {
      await fetch(getApiUrl('backend.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'log_activity',
          activity_type: 'WAREHOUSE_FIFO_BATCH_ERROR',
          description: `Failed to create FIFO batch for Product ID ${productId}: ${error.message}`,
          table_name: 'tbl_fifo_stock',
          record_id: productId,
        }),
      });
    } catch (sessionError) {
      console.warn("⚠️ Failed to log error:", sessionError);
    }
    
    safeToast("error", "Error creating FIFO batch:", error);
    return { success: false, error: error.message };
  }
}

// New function to duplicate product for batches
async function duplicateProductBatches(productId, batchIds = [22, 23]) {
  try {
    const response = await handleApiCall("duplicate_product_batches", { 
      product_id: productId,
      batch_ids: batchIds
    });
    return response;
  } catch (error) {
    safeToast("error", "Error duplicating product batches:", error);
    return { success: false, error: error.message };
  }
}

function Warehouse() {
  const { theme } = useTheme();
  const { settings, isProductExpiringSoon, isProductExpired, getExpiryStatus, isStockLow } = useSettings();

  // Smart product type detection function
  function detectProductType(productName) {
    if (!productName) return "Non-Medicine";
    
    const medicineKeywords = [
      'biogesic', 'paracetamol', 'acetaminophen', 'ibuprofen', 'aspirin',
      'vitamin', 'vitamins', 'supplement', 'medicine', 'tablet', 'tablets',
      'capsule', 'capsules', 'syrup', 'injection', 'drops', 'cream', 'ointment',
      'antibiotic', 'antihistamine', 'antacid', 'laxative', 'pain reliever',
      'fever reducer', 'cough', 'cold', 'flu', 'allergy', 'asthma',
      'diabetes', 'blood pressure', 'cholesterol', 'heart', 'liver',
      'kidney', 'stomach', 'digestive', 'sleep', 'anxiety', 'depression'
    ];
    
    const lowerName = productName.toLowerCase();
    
    // Check for medicine keywords
    for (const keyword of medicineKeywords) {
      if (lowerName.includes(keyword)) {
        console.log(`🔍 Auto-detected MEDICINE: "${productName}" contains "${keyword}"`);
        return "Medicine";
      }
    }
    
    // Check for mg dosage (common in medicines)
    if (/\d+\s*mg\b/i.test(productName)) {
      console.log(`🔍 Auto-detected MEDICINE: "${productName}" contains mg dosage`);
      return "Medicine";
    }
    
    // Check for medicine brand names
    const medicineBrands = [
      'biogesic', 'alaxan', 'advil', 'tylenol', 'motrin', 'aleve',
      'benadryl', 'claritin', 'zyrtec', 'pepcid', 'prilosec',
      'metformin', 'lisinopril', 'amlodipine', 'atorvastatin'
    ];
    
    for (const brand of medicineBrands) {
      if (lowerName.includes(brand)) {
        console.log(`🔍 Auto-detected MEDICINE: "${productName}" contains brand "${brand}"`);
        return "Medicine";
      }
    }
    
    console.log(`🔍 Auto-detected NON-MEDICINE: "${productName}" - no medicine keywords found`);
    return "Non-Medicine";
  }

  // Helper function to calculate stock status based on quantity and settings
  const getStockStatus = (quantity) => {
    const qty = parseInt(quantity || 0);
    if (qty <= 0) {
      return 'out of stock';
    } else if (qty <= settings.lowStockThreshold) {
      return 'low stock';
    } else {
      return 'in stock';
    }
  };
    // State Management
    const [scannerStatusMessage, setScannerStatusMessage] = useState("🔍 Scanner is ready and active - Scan any barcode to continue");
    const [scanTimeout, setScanTimeout] = useState(null);
    
    // Product name/barcode checking states (smart detection)
    const [productNameInput, setProductNameInput] = useState("");
    const [productNameStatusMessage, setProductNameStatusMessage] = useState("📝 Enter product name or barcode to check");
    const [isCheckingProductName, setIsCheckingProductName] = useState(false);
  
    const [inventoryData, setInventoryData] = useState([])
    const [suppliersData, setSuppliersData] = useState([])
    const [batchData, setBatchData] = useState([])
    const [brandsData, setBrandsData] = useState([])
    const [categoriesData, setCategoriesData] = useState([])
    const [searchTerm, setSearchTerm] = useState("")
    const [loading, setLoading] = useState(false)
    const [showAddModal, setShowAddModal] = useState(false)
    const [showSupplierModal, setShowSupplierModal] = useState(false)
    const [showEditModal, setShowEditModal] = useState(false)
    const [showEditProductModal, setShowEditProductModal] = useState(false)
    const [showDeleteModal, setShowDeleteModal] = useState(false)
    const [activeTab, setActiveTab] = useState("products")
    const [currentLocation, setCurrentLocation] = useState("warehouse")
    const [scannerActive, setScannerActive] = useState(settings.barcodeScanning) // Scanner state based on settings
    const [scannedBarcode, setScannedBarcode] = useState("")
    const [selectedItem, setSelectedItem] = useState(null)
    const [useSameBatch, setUseSameBatch] = useState(true)
    const [showProductModal, setShowProductModal] = useState(false);
    const [selectedProducts, setSelectedProducts] = useState([]);
    
    // New state for barcode scanning modals
    const [showUpdateStockModal, setShowUpdateStockModal] = useState(false);
    const [showNewProductModal, setShowNewProductModal] = useState(false);
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
    const [showFifoModal, setShowFifoModal] = useState(false);
    
    // Notification states
    const [showNotifications, setShowNotifications] = useState(false);
    const [notifications, setNotifications] = useState({
      expiring: [],
      expired: [],
      lowStock: [],
      outOfStock: []
    });
    const [alertCount, setAlertCount] = useState(0);
    const [fifoStockData, setFifoStockData] = useState([]);
    const [allBatchesData, setAllBatchesData] = useState([]);
    
    // Expiring batch modal states
    const [showExpiringBatchModal, setShowExpiringBatchModal] = useState(false);
    const [selectedExpiringProduct, setSelectedExpiringProduct] = useState(null);
    const [selectedProductForFifo, setSelectedProductForFifo] = useState(null);
    const [showQuantityHistoryModal, setShowQuantityHistoryModal] = useState(false);
    const [selectedProductForHistory, setSelectedProductForHistory] = useState(null);
    const [quantityHistoryData, setQuantityHistoryData] = useState([]);
      const [showCurrentFifoData, setShowCurrentFifoData] = useState(false);
    
    // New state for temporary product storage
    const [temporaryProducts, setTemporaryProducts] = useState([]);
    const [showBatchEntryModal, setShowBatchEntryModal] = useState(false);
    const [currentBatchNumber, setCurrentBatchNumber] = useState(generateBatchRef()); // Generate batch number on component mount
    
    // New state for medicine/non-medicine configuration modal
    const [showProductTypeModal, setShowProductTypeModal] = useState(false);
    const [productTypeForm, setProductTypeForm] = useState({
      product_type: "",
      configMode: "bulk", // Default to bulk mode
      // Medicine fields
      boxes: "",
      strips_per_box: "",
      tablets_per_strip: "",
      total_tablets: "",
      // Non-Medicine fields
      pieces_per_pack: "",
      total_pieces: ""
    });
    
    // Current user/employee information for tracking who created/modified items
    const [currentUser, setCurrentUser] = useState("admin"); // Default user, can be updated
    const [userRole, setUserRole] = useState("admin"); // Store user role

    const [newProductForm, setNewProductForm] = useState({
      product_name: "",
      category_id: "",
      product_type: "", // Medicine or Non-Medicine
      configMode: "bulk", // Default to bulk mode
      barcode: "",
      description: "",
      srp: "",
      brand_id: "",
      brand_search: "",
      supplier_id: "",
      expiration: "",
      date_added: new Date().toISOString().split('T')[0], // Auto-set current date
      batch: "", // Will be set to currentBatchNumber
      order_number: "",
      prescription: 0,
      bulk: 0,
      // Medicine fields
      boxes: "",
      strips_per_box: "",
      tablets_per_strip: "",
      total_tablets: "",
      // Non-Medicine fields
      pieces_per_pack: "",
      total_pieces: ""
    });
    
    // Get current user from localStorage or session
    useEffect(() => {
      // Check sessionStorage first (from login)
      const userSession = sessionStorage.getItem('user_data');
      if (userSession) {
        try {
          const userData = JSON.parse(userSession);
          if (userData.role) {
            setUserRole(userData.role);
            // Set currentUser based on role
            if (userData.role.toLowerCase() === 'admin') {
              setCurrentUser("admin");
            } else if (userData.role.toLowerCase() === 'inventory manager') {
              setCurrentUser("inventory");
            } else {
              // For other roles, use username or full_name
              setCurrentUser(userData.username || userData.full_name || "admin");
            }
          }
          // Fallback to old logic if role is not available
          if (userData.employee_name || userData.username) {
            setCurrentUser(userData.employee_name || userData.username);
          }
        } catch (error) {
          // "Could not parse user session, using default");
        }
      } else {
        // Fallback to localStorage
        const userSession = localStorage.getItem('user_session');
        if (userSession) {
          try {
            const userData = JSON.parse(userSession);
            if (userData.employee_name || userData.username) {
              setCurrentUser(userData.employee_name || userData.username);
            }
          } catch (error) {
            // "Could not parse user session, using default");
          }
        }
      }
      
      // Also check for saved employee name (but prioritize role-based setting)
      const savedEmployee = localStorage.getItem('warehouse_employee');
      if (savedEmployee && !userSession) {
        setCurrentUser(savedEmployee);
      }
    }, []);

    // Initialize transfer batch details table
    useEffect(() => {
      const initializeTransferBatchTable = async () => {
        try {
          await handleApiCall("create_transfer_batch_details_table");
          
        } catch (error) {
          safeToast("error", "❌ Error initializing transfer batch details table:", error);
        }
      };
      
      initializeTransferBatchTable();
    }, []);

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

    useEffect(() => {
    let buffer = "";
    let timeout;
  
    const handleKeyDown = (e) => {
      if (!scannerActive) return;
  
      // Skip navigation keys and form controls to avoid interfering with normal form usage
      const navigationKeys = ['Tab', 'Escape', 'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12'];
      const isNavigationKey = navigationKeys.includes(e.key);
      const isFormElement = e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT';
      
      // Don't interfere with navigation or form elements
      if (isNavigationKey || isFormElement) {
        return;
      }
  
      // "Key pressed:", e.key, "KeyCode:", e.keyCode, "Scanner active:", scannerActive);
  
      if (timeout) clearTimeout(timeout);
  
      // Accept Enter key to complete scan
      if (e.key === "Enter") {
        if (buffer.length > 0) {
          // "Barcode scanned:", buffer);
          handleScannerOperation("SCAN_COMPLETE", { barcode: buffer });
          buffer = "";
        }
      } else {
        // Accept all characters (not just numbers) for barcode scanning
        buffer += e.key;
        // "Buffer updated:", buffer);
        timeout = setTimeout(() => {
          // "Buffer cleared due to timeout");
          buffer = ""; // Clear buffer after inactivity
        }, 1000); // Increased timeout to 1 second
      }
    };
  
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [scannerActive]);
  
  
    const [filterOptions, setFilterOptions] = useState({
      category: "",
      supplier: "",
      status: "",
      prescription: false,
      bulk: false,
      stockStatus: "all",
    })
  
    // Generate batch reference function
    function generateBatchRef() {
      const now = new Date()
      const yyyy = now.getFullYear()
      const mm = String(now.getMonth() + 1).padStart(2, "0")
      const dd = String(now.getDate()).padStart(2, "0")
      const hh = String(now.getHours()).padStart(2, "0")
      const mi = String(now.getMinutes()).padStart(2, "0")
      const ss = String(now.getSeconds()).padStart(2, "0")
  
      return `BR-${yyyy}${mm}${dd}-${hh}${mi}${ss}`
    }
  
    // Removed unused form state variables since we're using modals now
  
    const [stats, setStats] = useState({
      totalProducts: 0,
      totalSuppliers: 0,
      warehouseValue: 0,
      totalQuantity: 0,
      lowStockItems: 0,
      expiringSoon: 0,
    })
  
    // Supplier form data
    const [supplierFormData, setSupplierFormData] = useState({
      supplier_name: "",
      supplier_address: "",
      supplier_contact: "",
      supplier_email: "",
      order_level: "",
      primary_phone: "",
      primary_email: "",
      contact_person: "",
      contact_title: "",
      payment_terms: "",
      lead_time_days: "",
      credit_rating: "",
      notes: "",
    });
  
    // Product form data
    const [formData, setFormData] = useState({
      product_name: "",
      barcode: "",
      category_id: "",
      description: "",
      prescription: 0,
      bulk: 0,
      expiration: "",
      quantity: 0,
      supplier_id: "",
      location_id: "",
      brand: "",
    });
  
  
    // Edit form data
    const [editFormData, setEditFormData] = useState({})
    const [editProductFormData, setEditProductFormData] = useState({
      product_name: "",
      category_id: "",
      barcode: "",
      description: "",
      srp: "",
      brand_id: "",
      quantity: "",
      supplier_id: "",
      expiration: "",
      prescription: 0,
      bulk: 0
    })
  
  
    // FIXED API Functions with better error handling
    async function handleCrudOperation(operation, data) {
      switch (operation) {
        case "DELETE_PRODUCT":
          setLoading(true);
          try {
            const response = await handleApiCall("delete_product", {
              product_id: data.product_id,
              reason: "Archived from warehouse management",
              archived_by: "admin"
            });
            if (response.success) {
              safeToast("success", "Product archived successfully");
              setShowDeleteModal(false);
              setSelectedItem(null);
              loadData("products");
            } else {
              safeToast("error", response.message || "Failed to delete product");
            }
          } catch (error) {
            safeToast("error", "Error deleting product:", error);
            safeToast("error", "Failed to delete product");
          } finally {
            setLoading(false);
          }
          break;
    
        case "CREATE_SUPPLIER":
          setLoading(true);
          if (
            !data.supplier_name ||
            !data.supplier_contact ||
            !data.supplier_email
          ) {
            safeToast("error", "Supplier name, contact, and email are required");
            setLoading(false);
            return;
          }
          // Fix lead_time_days: send as integer or omit
          if (data.lead_time_days === "" || data.lead_time_days === undefined || data.lead_time_days === null) {
            delete data.lead_time_days;
          } else {
            data.lead_time_days = parseInt(data.lead_time_days, 10);
            if (isNaN(data.lead_time_days)) delete data.lead_time_days;
          }
          try {
            const response = await handleApiCall("add_supplier", data);
            if (response.success) {
              safeToast("success", response.message || "Supplier added successfully");
              setShowSupplierModal(false);
              clearSupplierForm();
              loadData("suppliers");
            } else {
              safeToast("error", response.message || "Failed to add supplier");
            }
          } catch (error) {
            safeToast("error",
              "Failed to add supplier: " +
                (error?.response?.data?.message || error.message)
            );
            safeToast("error", "Error adding supplier:", error);
          } finally {
            setLoading(false);
          }
          break;
    
        case "UPDATE_SUPPLIER":
          setLoading(true);
          const updateData = {
            ...data,
            supplier_id: selectedItem?.supplier_id,
          };
          try {
            const response = await handleApiCall("update_supplier", updateData);
            if (response.success) {
              safeToast("success", "Supplier updated successfully");
              setShowEditModal(false);
              setSelectedItem(null);
              clearEditForm();
              loadData("suppliers");
            } else {
              safeToast("error", response.message || "Failed to update supplier");
            }
          } catch (error) {
            safeToast("error", "Error updating supplier:", error);
            safeToast("error", "Failed to update supplier");
          } finally {
            setLoading(false);
          }
          break;

        case "UPDATE_PRODUCT":
          setLoading(true);
          const updateProductData = {
            ...data,
            product_id: selectedItem?.product_id,
          };
          try {
            const response = await handleApiCall("update_product", updateProductData);
            if (response.success) {
              safeToast("success", "Product updated successfully");
              setShowEditProductModal(false);
              setSelectedItem(null);
              setEditProductFormData({});
              loadData("products");
            } else {
              safeToast("error", response.message || "Failed to update product");
            }
          } catch (error) {
            safeToast("error", "Error updating product:", error);
            safeToast("error", "Failed to update product");
          } finally {
            setLoading(false);
          }
          break;
    
        case "DELETE_SUPPLIER":
          setLoading(true);
          try {
            const response = await handleApiCall("delete_supplier", {
              supplier_id: data.supplier_id,
              reason: "Archived from warehouse management",
              archived_by: "admin"
            });
            if (response.success) {
              safeToast("success", "Supplier archived successfully");
              setShowDeleteModal(false);
              setSelectedItem(null);
              loadData("suppliers");
            } else {
              safeToast("error", response.message || "Failed to delete supplier");
            }
          } catch (error) {
            safeToast("error", "Error deleting supplier:", error);
            safeToast("error", "Failed to delete supplier");
          } finally {
            setLoading(false);
          }
          break;
    
        case "CREATE_PRODUCT":
          // This case is now handled in the modal handlers
          // "CREATE_PRODUCT case is deprecated - use modal handlers instead");
          break;
    
        default:
          safeToast("error", "Unknown CRUD operation:", operation);
          safeToast("error", "Unknown operation: " + operation);
      }
    }
    
      // Function to get the earliest expiring batch for a product
  async function getEarliestExpiringBatch(productId) {
    try {
      const fifoResponse = await getFifoStock(productId);
      if (fifoResponse.success && fifoResponse.data && fifoResponse.data.length > 0) {
        // Find the batch with the earliest expiration date
        const earliestBatch = fifoResponse.data.reduce((earliest, current) => {
          const earliestDate = new Date(earliest.expiration_date);
          const currentDate = new Date(current.expiration_date);
          return currentDate < earliestDate ? current : earliest;
        });
        return earliestBatch.expiration_date;
      }
    } catch (error) {
      safeToast("error", "Error getting earliest expiring batch:", error);
    }
    return null;
  }

  // Calculate notifications for expiring and low stock products
  async function calculateNotifications(productList) {
    const today = new Date();
    // "🔔 Calculating notifications for", productList.length, "products");
    // "🔔 Expiry alerts enabled:", settings.expiryAlerts);
    // "🔔 Expiry warning days:", settings.expiryWarningDays);

    // Get earliest expiring dates for all products
    const productsWithEarliestExpiry = await Promise.all(
      productList.map(async (product) => {
        const earliestExpiry = await getEarliestExpiringBatch(product.product_id);
        const productWithExpiry = {
          ...product,
          earliest_expiration: earliestExpiry || product.expiration
        };
        // Fetch batch info for expiring product
        try {
          const fifoResponse = await getFifoStock(product.product_id);
          if (fifoResponse.success && fifoResponse.data && fifoResponse.data.length > 0) {
            // Find the batch that matches the earliest expiration
            const expiringBatch = fifoResponse.data.find(batch => 
              batch.expiration_date === productWithExpiry.earliest_expiration
            );
            return {
              ...productWithExpiry,
              expiring_batch: expiringBatch ? {
                batch_number: expiringBatch.batch_number,
                batch_reference: expiringBatch.batch_reference,
                quantity: expiringBatch.available_quantity,
                days_until_expiry: Math.ceil((new Date(productWithExpiry.earliest_expiration) - new Date()) / (1000 * 60 * 60 * 24))
              } : null
            };
          }
        } catch (error) {
          safeToast("error", "Error getting batch info for expiring product:", error);
        }
        return productWithExpiry;
      })
    );

    // Build notification arrays robustly
    const expiringWithBatchInfo = productsWithEarliestExpiry.filter(p => {
      if (!p.earliest_expiration) return false;
      const days = Math.ceil((new Date(p.earliest_expiration) - new Date()) / (1000 * 60 * 60 * 24));
      return days > 0 && days <= (settings.expiryWarningDays || 30);
    });
    const expired = productsWithEarliestExpiry.filter(p => {
      if (!p.earliest_expiration) return false;
      return new Date(p.earliest_expiration) < new Date();
    });
      const lowStock = productsWithEarliestExpiry.filter(p => {
        const qty = parseInt(p.total_quantity || p.quantity || p.product_quantity || 0);
        return qty > 0 && qty <= (settings.lowStockThreshold || 10);
      });
      const outOfStock = productsWithEarliestExpiry.filter(p => {
        const qty = parseInt(p.total_quantity || p.quantity || p.product_quantity || 0);
        return qty <= 0;
      });

    setNotifications({
      expiring: expiringWithBatchInfo.sort((a, b) => new Date(a.earliest_expiration) - new Date(b.earliest_expiration)),
      expired: expired.sort((a, b) => new Date(a.earliest_expiration) - new Date(b.earliest_expiration)),
      lowStock: lowStock.sort((a, b) => parseInt(a.total_quantity || a.quantity || 0) - parseInt(b.total_quantity || b.quantity || 0)),
      outOfStock: outOfStock
    });
    }
  
    // FIXED Data Loading Functions
    function loadData(dataType) {
      switch (dataType) {
        case "suppliers":
          handleApiCall("get_suppliers")
            .then((response) => {
              
              let suppliersArray = []
  
              if (response.success && Array.isArray(response.data)) {
                suppliersArray = response.data
              } else if (Array.isArray(response.data)) {
                suppliersArray = response.data
              }
  
              setSuppliersData(suppliersArray)
              updateStats("totalSuppliers", suppliersArray.length)
              // "Suppliers loaded:", suppliersArray.length)
            })
            .catch((error) => {
              safeToast("error", "Error loading suppliers:", error)
              safeToast("error", "Failed to load suppliers")
              setSuppliersData([])
            })
          break
            case "products":
                // "🔄 Loading warehouse products with oldest batch info and expiration data...");
                loadProductsWithOldestBatch()
                  .then(async (productsWithBatchInfo) => {
                    // "📦 Products with batch info loaded:", productsWithBatchInfo.length);
                    
                    // Filter out archived products
const activeProducts = productsWithBatchInfo.filter(
  (product) => (product.status || "").toLowerCase() !== "archived"
);
// "🔍 Active products after filtering:", activeProducts.length);
// "🔍 Products with batch info and expiration data loaded");
// "📅 Sample product expiration data:", activeProducts[0]?.expiration || "No expiration data");

console.log("Active products after filtering:", activeProducts);
setInventoryData(activeProducts);
await calculateNotifications(activeProducts);
updateStats("totalProducts", activeProducts.length);
calculateWarehouseValue(activeProducts);
calculateLowStockAndExpiring(activeProducts);
                    // "✅ Products with batch info loaded successfully:", activeProducts.length, "products");
                  })
                  .catch((error) => {
                    safeToast("error", "❌ Error loading products with batch info:", error);
                    safeToast("error", "Failed to load products with batch and expiration information");
                    safeToast("error", "Failed to load products, keeping previous data."); // Do not clear inventoryData; keep previous products visible
                  });
              break;
  
  
  
        case "batches":
          handleApiCall("get_batches")
            .then((response) => {
              // "Batches response:", response.data)
              let batchesArray = []
  
              if (Array.isArray(response.data)) {
                batchesArray = response.data
              } else if (response.data && Array.isArray(response.data.data)) {
                batchesArray = response.data.data
              }
  
              setBatchData(batchesArray)
              // "Batches loaded:", batchesArray.length)
            })
            .catch((error) => {
              safeToast("error", "Failed to load batches: " + error.message)
              safeToast("error", "Failed to load batches")
              setBatchData([])
            })
          break
  
        case "brands":
          // Load brands from your database
          handleApiCall("get_brands")
            .then((response) => {
              // "Brands response:", response.data)
              let brandsArray = []
  
              if (Array.isArray(response.data)) {
                brandsArray = response.data
              } else if (response.data && Array.isArray(response.data.data)) {
                brandsArray = response.data.data
              }
  
              setBrandsData(brandsArray)
              // "Brands loaded:", brandsArray.length)
            })
            .catch((error) => {
              safeToast("error", "Error loading brands:", error)
              // Set default brands if API fails
              setBrandsData([
                { brand_id: 23, brand: "dawdawdaw" },
                { brand_id: 24, brand: "trust" },
                { brand_id: 25, brand: "rightmid" },
                { brand_id: 26, brand: "daw" },
                { brand_id: 27, brand: "dwa" },
                { brand_id: 28, brand: "dawd" },
              ])
            })
          break

        case "categories":
          // Load categories from your database
          // "🔄 Loading categories...");
          handleApiCall("get_categories")
            .then((response) => {
              // "📦 Categories API response:", response);
              // "📦 Categories response.data:", response.data);
              let categoriesArray = []
  
              if (Array.isArray(response.data)) {
                categoriesArray = response.data
                // "✅ Categories loaded from response.data array:", categoriesArray);
              } else if (response.data && Array.isArray(response.data.data)) {
                categoriesArray = response.data.data
                // "✅ Categories loaded from response.data.data array:", categoriesArray);
              } else {
                console.warn("⚠️ Unexpected categories response format:", response);
              }
  
              // "🔍 Final categoriesArray before setting:", categoriesArray);
              // "🔍 categoriesArray.length:", categoriesArray.length);
              // "🔍 categoriesArray content:", JSON.stringify(categoriesArray, null, 2));
              
              setCategoriesData(categoriesArray)
              // "✅ Categories loaded successfully:", categoriesArray.length, "categories");
              // "📋 Categories data:", categoriesArray);
            })
            .catch((error) => {
              safeToast("error", "❌ Error loading categories:", error)
              safeToast("error", "Failed to load categories from database")
            })
          break
  
        case "all":
          loadData("suppliers")
          loadData("products")
          loadData("batches")
          loadData("brands")
          loadData("categories")
          break
  
        default:
          safeToast("error", "Unknown data type:", dataType)
      }
    }
  

    
    function updateStats(statName, value) {
      setStats((prev) => ({
        ...prev,
        [statName]: value,
      }))
    }
  
    function calculateWarehouseValue(products) {
  const totalValue = products.reduce((sum, product) => {
    return sum + (Number.parseFloat(product.srp) || 0) * (Number.parseFloat(product.total_quantity || product.product_quantity || product.quantity || 0))
  }, 0)

  // Calculate total quantity from product quantity (sum of all batches)
  const totalProductQuantity = products.reduce((sum, product) => sum + (Number(product.total_quantity || product.product_quantity || product.quantity || 0)), 0);
  
  setStats((prev) => ({
    ...prev,
    totalQuantity: totalProductQuantity,
    totalProductQuantity: totalProductQuantity,
    warehouseValue: totalValue,
  }))
}
  
    // Reset Functions
    function clearSupplierForm() {
      setSupplierFormData({
        supplier_name: "",
        supplier_address: "",
        supplier_contact: "",
        supplier_email: "",
        order_level: "",
        primary_phone: "",
        primary_email: "",
        contact_person: "",
        contact_title: "",
        payment_terms: "",
        lead_time_days: "",
        credit_rating: "",
        notes: "",
      })
    }
  
    function clearEditForm() {
      setEditFormData({})
    }
  
    // Form Handlers
    function handleSupplierInputChange(field, value) {
      setSupplierFormData((prev) => ({
        ...prev,
        [field]: value,
      }))
    }
  
    function handleEditInputChange(field, value) {
      setEditFormData((prev) => ({
        ...prev,
        [field]: value,
      }))
    }

    function handleEditProductInputChange(field, value) {
      setEditProductFormData((prev) => ({
        ...prev,
        [field]: value,
      }))
    }
  
    // Enhanced Product Name/Barcode Checking Functions
  async function handleProductNameCheck(input) {
    if (!input || input.trim() === "") {
      setProductNameStatusMessage("❌ Please enter a product name or barcode");
      return;
    }

    setIsCheckingProductName(true);
    
    // Smart detection: Check if input looks like a barcode (numbers/alphanumeric without spaces)
    const looksLikeBarcode = /^[A-Za-z0-9-_]+$/.test(input.trim()) && input.trim().length >= 6;
    
    if (looksLikeBarcode) {
      setProductNameStatusMessage("🔍 Detecting barcode format - checking barcode...");
      console.log("🔍 Input detected as BARCODE:", input);
      
      try {
        // Search by barcode first in local inventory
        const existingProductInInventory = inventoryData.find(product => 
          product.barcode && product.barcode === input.trim()
        );
        
        if (existingProductInInventory) {
          console.log("✅ Product found by barcode in inventory data:", existingProductInInventory);
          
          // Use stored product_type from database, with fallback to auto-detection
          let productType = existingProductInInventory.product_type || detectProductType(existingProductInInventory.product_name);
          
          if (!existingProductInInventory.product_type) {
            console.log("🔍 Barcode check: No stored product_type, using auto-detection for:", existingProductInInventory.product_name);
            
            // Auto-detection logic
            const medicineCategories = [
              "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
              "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
              "Vitamins", "Supplements", "Health", "Medical"
            ];
            const categoryName = existingProductInInventory.category_name || existingProductInInventory.category || "";
            
            if (medicineCategories.some(medCat => 
              categoryName.toLowerCase().includes(medCat.toLowerCase())
            )) {
              productType = "Medicine";
              console.log("🔍 Barcode check: Auto-detected Medicine product based on category:", categoryName);
            } else {
              const medicineKeywords = [
                "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
                "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
                "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
                "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
              ];
              const productName = (existingProductInInventory.product_name || "").toLowerCase();
              
              if (medicineKeywords.some(keyword => productName.includes(keyword))) {
                productType = "Medicine";
                console.log("🔍 Barcode check: Auto-detected Medicine product based on product name:", existingProductInInventory.product_name);
              } else {
                productType = "Non-Medicine";
                console.log("🔍 Barcode check: Auto-detected Non-Medicine product for:", existingProductInInventory.product_name);
              }
            }
          } else {
            console.log("🔍 Barcode check: Using stored product_type:", existingProductInInventory.product_type, "for:", existingProductInInventory.product_name);
          }
          
          // Add product_type to the product object
          const productWithType = {
            ...existingProductInInventory,
            product_type: productType
          };
          
          console.log("🔍 Barcode check: Final product type determined:", productType);
          setExistingProduct(productWithType);
          setNewStockQuantity("");
          
          const hasBulkFields = existingProductInInventory.boxes || existingProductInInventory.strips_per_box || 
                                existingProductInInventory.tablets_per_strip || 
                                existingProductInInventory.pieces_per_pack;
          setStockUpdateConfigMode(hasBulkFields ? "bulk" : "pieces");
          
          setShowUpdateStockModal(true);
          setProductNameStatusMessage("✅ Product found by barcode! Opening update stock modal.");
        } else {
          console.log("🔍 Product not in inventory data, checking API by barcode...");
          // Check API by barcode
          const barcodeCheck = await checkBarcodeExists(input.trim());
          console.log("🔍 Barcode check result:", barcodeCheck);
          console.log("🔍 MANUAL BARCODE CHECK - product object:", barcodeCheck.product);
          console.log("🔍 MANUAL BARCODE CHECK - product exists:", !!barcodeCheck.product);
          
          // SIMPLE CHECK - if product exists in response, it was found
          const productFound = barcodeCheck.product !== null && barcodeCheck.product !== undefined && typeof barcodeCheck.product === 'object';
          console.log("🔍 MANUAL BARCODE CHECK - productFound:", productFound);
          
          if (productFound) {
            console.log("✅ Product found by barcode via API:", barcodeCheck.product);
            
            // Use stored product_type from database, with fallback to auto-detection
            let productType = barcodeCheck.product.product_type || detectProductType(barcodeCheck.product.product_name);
            
            if (!barcodeCheck.product.product_type) {
              console.log("🔍 Manual barcode API check: No stored product_type, using auto-detection for:", barcodeCheck.product.product_name);
              
              // Auto-detection logic
              const medicineCategories = [
                "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
                "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
                "Vitamins", "Supplements", "Health", "Medical"
              ];
              const categoryName = barcodeCheck.product.category_name || barcodeCheck.product.category || "";
              
              if (medicineCategories.some(medCat => 
                categoryName.toLowerCase().includes(medCat.toLowerCase())
              )) {
                productType = "Medicine";
                console.log("🔍 Manual barcode API check: Auto-detected Medicine product based on category:", categoryName);
              } else {
                const medicineKeywords = [
                  "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
                  "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
                  "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
                  "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
                ];
                const productName = (barcodeCheck.product.product_name || "").toLowerCase();
                
                if (medicineKeywords.some(keyword => productName.includes(keyword))) {
                  productType = "Medicine";
                  console.log("🔍 Manual barcode API check: Auto-detected Medicine product based on product name:", barcodeCheck.product.product_name);
                } else {
                  productType = "Non-Medicine";
                  console.log("🔍 Manual barcode API check: Auto-detected Non-Medicine product for:", barcodeCheck.product.product_name);
                }
              }
            } else {
              console.log("🔍 Manual barcode API check: Using stored product_type:", barcodeCheck.product.product_type, "for:", barcodeCheck.product.product_name);
            }
            
            // Add product_type to the product object
            const productWithType = {
              ...barcodeCheck.product,
              product_type: productType
            };
            
            console.log("🔍 Manual barcode API check: Final product type determined:", productType);
            setExistingProduct(productWithType);
            setNewStockQuantity("");
            
            const hasBulkFields = barcodeCheck.product.boxes || barcodeCheck.product.strips_per_box || 
                                  barcodeCheck.product.tablets_per_strip || 
                                  barcodeCheck.product.pieces_per_pack;
            setStockUpdateConfigMode(hasBulkFields ? "bulk" : "pieces");
            
            setShowUpdateStockModal(true);
            setProductNameStatusMessage("✅ Product found by barcode! Opening update stock modal.");
          } else {
            console.log("❌ Barcode not found, opening new product modal");
            setNewProductForm({
              product_name: "",
              category_id: "",
              product_type: "",
              configMode: "bulk",
              barcode: input.trim(), // Pre-fill barcode
              description: "",
              srp: "",
              brand_id: "",
              brand_search: "",
              supplier_id: "",
              expiration: "",
              date_added: new Date().toISOString().split('T')[0],
              batch: generateBatchRef(),
              order_number: "",
              prescription: 0,
              bulk: 0,
              boxes: "",
              strips_per_box: "",
              tablets_per_strip: "",
              total_tablets: "",
              pieces_per_pack: "",
              total_pieces: ""
            });
            setShowNewProductModal(true);
            setProductNameStatusMessage("✅ New barcode detected! Opening new product modal.");
          }
        }
      } catch (error) {
        safeToast("error", "Error checking barcode:", error);
        setProductNameStatusMessage("❌ Error checking barcode. Please try again.");
      } finally {
        setIsCheckingProductName(false);
        setTimeout(() => {
          setProductNameStatusMessage("📝 Enter product name or barcode to check");
        }, 3000);
      }
      return;
    }
    
    // If not barcode format, treat as product name
    setProductNameStatusMessage("🔍 Checking if product name exists...");
    console.log("🔍 Input detected as PRODUCT NAME:", input);

    try {
      // First, try to find the product in existing inventory data
      const existingProductInInventory = inventoryData.find(product => 
        product.product_name && product.product_name.toLowerCase().includes(input.toLowerCase())
      );
      
      if (existingProductInInventory) {
        console.log("✅ Product found in inventory data:", existingProductInInventory);
        
        // Use stored product_type from database, with fallback to auto-detection
        let productType = existingProductInInventory.product_type || detectProductType(existingProductInInventory.product_name);
        
        if (!existingProductInInventory.product_type) {
          console.log("🔍 Product name check: No stored product_type, using auto-detection for:", existingProductInInventory.product_name);
          
          // Auto-detection logic
          const medicineCategories = [
            "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
            "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
            "Vitamins", "Supplements", "Health", "Medical"
          ];
          const categoryName = existingProductInInventory.category_name || existingProductInInventory.category || "";
          
          if (medicineCategories.some(medCat => 
            categoryName.toLowerCase().includes(medCat.toLowerCase())
          )) {
            productType = "Medicine";
            console.log("🔍 Product name check: Auto-detected Medicine product based on category:", categoryName);
          } else {
            const medicineKeywords = [
              "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
              "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
              "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
              "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
            ];
            const productName = (existingProductInInventory.product_name || "").toLowerCase();
            
            if (medicineKeywords.some(keyword => productName.includes(keyword))) {
              productType = "Medicine";
              console.log("🔍 Product name check: Auto-detected Medicine product based on product name:", existingProductInInventory.product_name);
            } else {
              productType = "Non-Medicine";
              console.log("🔍 Product name check: Auto-detected Non-Medicine product for:", existingProductInInventory.product_name);
            }
          }
        } else {
          console.log("🔍 Product name check: Using stored product_type:", existingProductInInventory.product_type, "for:", existingProductInInventory.product_name);
        }
        
        // Add product_type to the product object
        const productWithType = {
          ...existingProductInInventory,
          product_type: productType
        };
        
        console.log("🔍 Product name check: Final product type determined:", productType);
        setExistingProduct(productWithType);
        setNewStockQuantity("");
        
        // Set default configuration mode based on product data
        const hasBulkFields = existingProductInInventory.boxes || existingProductInInventory.strips_per_box || 
                              existingProductInInventory.tablets_per_strip || 
                              existingProductInInventory.pieces_per_pack;
        setStockUpdateConfigMode(hasBulkFields ? "bulk" : "pieces");
        
        setShowUpdateStockModal(true);
        setProductNameStatusMessage("✅ Product found! Opening update stock modal.");
      } else {
        console.log("🔍 Product not in inventory data, checking API...");
        // If not in inventory, check API
        const productNameCheck = await checkProductNameExists(input);
        console.log("🔍 Product name check result:", productNameCheck);
        
        // Handle both API response formats:
        // 1. sales_api.php: { success: true, found: true, product: {...} }
        // 2. backend.php: { success: true, product: {...} } (no 'found' field)
        const productFound = productNameCheck.success && 
                             (productNameCheck.found === true || (productNameCheck.found === undefined && productNameCheck.product)) && 
                             productNameCheck.product;
        
        if (productFound) {
          console.log("✅ Product found via API, opening update stock modal:", productNameCheck.product);
          
          // Use stored product_type from database, with fallback to auto-detection
          let productType = productNameCheck.product.product_type || detectProductType(productNameCheck.product.product_name);
          
          if (!productNameCheck.product.product_type) {
            console.log("🔍 API product name check: No stored product_type, using auto-detection for:", productNameCheck.product.product_name);
            
            // Auto-detection logic
            const medicineCategories = [
              "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
              "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
              "Vitamins", "Supplements", "Health", "Medical"
            ];
            const categoryName = productNameCheck.product.category_name || productNameCheck.product.category || "";
            
            if (medicineCategories.some(medCat => 
              categoryName.toLowerCase().includes(medCat.toLowerCase())
            )) {
              productType = "Medicine";
              console.log("🔍 API product name check: Auto-detected Medicine product based on category:", categoryName);
            } else {
              const medicineKeywords = [
                "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
                "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
                "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
                "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
              ];
              const productName = (productNameCheck.product.product_name || "").toLowerCase();
              
              if (medicineKeywords.some(keyword => productName.includes(keyword))) {
                productType = "Medicine";
                console.log("🔍 API product name check: Auto-detected Medicine product based on product name:", productNameCheck.product.product_name);
              } else {
                productType = "Non-Medicine";
                console.log("🔍 API product name check: Auto-detected Non-Medicine product for:", productNameCheck.product.product_name);
              }
            }
          } else {
            console.log("🔍 API product name check: Using stored product_type:", productNameCheck.product.product_type, "for:", productNameCheck.product.product_name);
          }
          
          // Add product_type to the product object
          const productWithType = {
            ...productNameCheck.product,
            product_type: productType
          };
          
          console.log("🔍 API product name check: Final product type determined:", productType);
          setExistingProduct(productWithType);
          setNewStockQuantity("");
          
          // Set default configuration mode based on product data
          const hasBulkFields = productNameCheck.product.boxes || productNameCheck.product.strips_per_box || 
                                productNameCheck.product.tablets_per_strip || 
                                productNameCheck.product.pieces_per_pack;
          setStockUpdateConfigMode(hasBulkFields ? "bulk" : "pieces");
          
          setShowUpdateStockModal(true);
          setProductNameStatusMessage("✅ Product found! Opening update stock modal.");
        } else {
          console.log("❌ Product not found, opening new product modal");
          // Product doesn't exist - show new product modal
          setNewProductForm({
            product_name: input, // Pre-fill with entered product name
            category_id: "",
            product_type: "",
            configMode: "bulk", // Default to bulk mode
            barcode: "", // Empty barcode for manual entry
            description: "",
            srp: "",
            brand_id: "",
            brand_search: "",
            supplier_id: "",
            expiration: "",
            date_added: new Date().toISOString().split('T')[0], // Auto-set current date
            batch: generateBatchRef(), // Auto-generate batch
            order_number: "",
            prescription: 0,
            bulk: 0,
            // Medicine fields
            boxes: "",
            strips_per_box: "",
            tablets_per_strip: "",
            total_tablets: "",
            // Non-Medicine fields
            pieces_per_pack: "",
            total_pieces: ""
          });
          setShowNewProductModal(true);
          setProductNameStatusMessage("✅ New product detected! Opening new product modal.");
        }
      }
    } catch (error) {
      safeToast("error", "Error checking product name:", error);
      setProductNameStatusMessage("❌ Error checking product name. Please try again.");
      safeToast("error", "Failed to check product name");
    } finally {
      setIsCheckingProductName(false);
      
      // Reset product name status after a delay
      setTimeout(() => {
        setProductNameStatusMessage("📝 Enter product name or barcode to check");
      }, 3000);
    }
  }

    // Enhanced Scanner Functions with Barcode Checking
  async function handleScannerOperation(operation, data) {
    // "Scanner operation:", operation, "Data:", data);
    
    switch (operation) {
      case "SCAN_COMPLETE":
        // "Scan complete with barcode:", data.barcode);
        // Keep scanner active after scan
        if (scanTimeout) clearTimeout(scanTimeout);
  
        const scanned = data.barcode;
        setScannedBarcode(scanned);
        setScannerStatusMessage("✅ Barcode received! Checking if product exists...");
  
        try {
          console.log("🔍 Checking barcode in database:", scanned);
          
          // First, try to find the product in existing inventory data
          const existingProductInInventory = inventoryData.find(product => product.barcode === scanned);
          
          if (existingProductInInventory) {
            console.log("✅ Product found in inventory data:", existingProductInInventory);
            console.log("🚪 OPENING MODAL: UPDATE STOCK MODAL (from local inventory)");
            
            // Use stored product_type from database, with fallback to auto-detection
            let productType = existingProductInInventory.product_type || detectProductType(existingProductInInventory.product_name);
            
            if (!existingProductInInventory.product_type) {
              console.log("🔍 Scanner: No stored product_type, using auto-detection for:", existingProductInInventory.product_name);
              
              // Auto-detection logic
              const medicineCategories = [
                "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
                "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
                "Vitamins", "Supplements", "Health", "Medical"
              ];
              const categoryName = existingProductInInventory.category_name || existingProductInInventory.category || "";
              
              if (medicineCategories.some(medCat => 
                categoryName.toLowerCase().includes(medCat.toLowerCase())
              )) {
                productType = "Medicine";
                console.log("🔍 Scanner: Auto-detected Medicine product based on category:", categoryName);
              } else {
                const medicineKeywords = [
                  "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
                  "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
                  "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
                  "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
                ];
                const productName = (existingProductInInventory.product_name || "").toLowerCase();
                
                if (medicineKeywords.some(keyword => productName.includes(keyword))) {
                  productType = "Medicine";
                  console.log("🔍 Scanner: Auto-detected Medicine product based on product name:", existingProductInInventory.product_name);
                } else {
                  productType = "Non-Medicine";
                  console.log("🔍 Scanner: Auto-detected Non-Medicine product for:", existingProductInInventory.product_name);
                }
              }
            } else {
              console.log("🔍 Scanner: Using stored product_type:", existingProductInInventory.product_type, "for:", existingProductInInventory.product_name);
            }
            
            // Add product_type to the product object
            const productWithType = {
              ...existingProductInInventory,
              product_type: productType
            };
            
            console.log("🔍 Scanner: Final product type determined:", productType);
            setExistingProduct(productWithType);
            setNewStockQuantity("");
            
            // Set default configuration mode based on product data
            const hasBulkFields = existingProductInInventory.boxes || existingProductInInventory.strips_per_box || 
                                  existingProductInInventory.tablets_per_strip || 
                                  existingProductInInventory.pieces_per_pack;
            setStockUpdateConfigMode(hasBulkFields ? "bulk" : "pieces");
            
            setShowUpdateStockModal(true);
            console.log("🚪 setShowUpdateStockModal(true) CALLED (from local)");
            setScannerStatusMessage("✅ Product found! Opening update stock modal.");
          } else {
            console.log("🔍 Product not in inventory data, checking API...");
            console.log("📊 Current inventoryData length:", inventoryData.length);
            console.log("📊 Scanned barcode:", scanned);
            
            // If not in inventory, check API
            const barcodeCheck = await checkBarcodeExists(scanned);
            console.log("🔍 Barcode check result:", barcodeCheck);
            console.log("🔍 barcodeCheck.success:", barcodeCheck.success);
            console.log("🔍 barcodeCheck.found:", barcodeCheck.found);
            console.log("🔍 barcodeCheck.product:", barcodeCheck.product);
            
            // EXTREME DEBUGGING - Log EVERYTHING
            console.log("🔍 ========== BARCODE CHECK DETAILS ==========");
            console.log("🔍 barcodeCheck object:", barcodeCheck);
            console.log("🔍 barcodeCheck.success type:", typeof barcodeCheck.success, "value:", barcodeCheck.success);
            console.log("🔍 barcodeCheck.found type:", typeof barcodeCheck.found, "value:", barcodeCheck.found);
            console.log("🔍 barcodeCheck.product type:", typeof barcodeCheck.product, "value:", barcodeCheck.product);
            console.log("🔍 barcodeCheck has 'product' property:", 'product' in barcodeCheck);
            console.log("🔍 barcodeCheck.product is truthy:", !!barcodeCheck.product);
            
            // SIMPLE CHECK - if product exists in response, it was found
            const productFound = barcodeCheck.product !== null && barcodeCheck.product !== undefined && typeof barcodeCheck.product === 'object';
            
            console.log("🔍 productFound result:", productFound);
            console.log("🔍 ==========================================");
            
            if (productFound) {
              console.log("✅ Product found via API, opening update stock modal:", barcodeCheck.product);
              console.log("🚪 OPENING MODAL: UPDATE STOCK MODAL");
              
              // Use stored product_type from database, with fallback to auto-detection
              let productType = barcodeCheck.product.product_type || detectProductType(barcodeCheck.product.product_name);
              
              if (!barcodeCheck.product.product_type) {
                console.log("🔍 Scanner API barcode check: No stored product_type, using auto-detection for:", barcodeCheck.product.product_name);
                
                // Auto-detection logic
                const medicineCategories = [
                  "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
                  "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
                  "Vitamins", "Supplements", "Health", "Medical"
                ];
                const categoryName = barcodeCheck.product.category_name || barcodeCheck.product.category || "";
                
                if (medicineCategories.some(medCat => 
                  categoryName.toLowerCase().includes(medCat.toLowerCase())
                )) {
                  productType = "Medicine";
                  console.log("🔍 Scanner API barcode check: Auto-detected Medicine product based on category:", categoryName);
                } else {
                  const medicineKeywords = [
                    "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
                    "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
                    "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
                    "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
                  ];
                  const productName = (barcodeCheck.product.product_name || "").toLowerCase();
                  
                  if (medicineKeywords.some(keyword => productName.includes(keyword))) {
                    productType = "Medicine";
                    console.log("🔍 Scanner API barcode check: Auto-detected Medicine product based on product name:", barcodeCheck.product.product_name);
                  } else {
                    productType = "Non-Medicine";
                    console.log("🔍 Scanner API barcode check: Auto-detected Non-Medicine product for:", barcodeCheck.product.product_name);
                  }
                }
              } else {
                console.log("🔍 Scanner API barcode check: Using stored product_type:", barcodeCheck.product.product_type, "for:", barcodeCheck.product.product_name);
              }
              
              // Add product_type to the product object
              const productWithType = {
                ...barcodeCheck.product,
                product_type: productType
              };
              
              console.log("🔍 Scanner API barcode check: Final product type determined:", productType);
              setExistingProduct(productWithType);
              setNewStockQuantity("");
              
              // Set default configuration mode based on product data
              const hasBulkFields = barcodeCheck.product.boxes || barcodeCheck.product.strips_per_box || 
                                    barcodeCheck.product.tablets_per_strip || 
                                    barcodeCheck.product.pieces_per_pack;
              setStockUpdateConfigMode(hasBulkFields ? "bulk" : "pieces");
              
              setShowUpdateStockModal(true);
              console.log("🚪 setShowUpdateStockModal(true) CALLED");
              setScannerStatusMessage("✅ Product found! Opening update stock modal.");
            } else {
              console.log("❌ Product not found, opening new product modal");
              console.log("🚪 OPENING MODAL: NEW PRODUCT MODAL");
              // Product doesn't exist - show new product modal
              setNewProductForm({
                product_name: "",
                category_id: "",
                product_type: "",
                configMode: "bulk", // Default to bulk mode
                barcode: scanned, // Pre-fill with scanned barcode
                description: "",
                srp: "",
                brand_id: "",
                brand_search: "",
                supplier_id: "",
                expiration: "",
                date_added: new Date().toISOString().split('T')[0], // Auto-set current date
                batch: generateBatchRef(), // Auto-generate batch
                order_number: "",
                prescription: 0,
                bulk: 0,
                // Medicine fields
                boxes: "",
                strips_per_box: "",
                tablets_per_strip: "",
                total_tablets: "",
                // Non-Medicine fields
                pieces_per_pack: "",
                total_pieces: ""
              });
              setShowNewProductModal(true);
              setScannerStatusMessage("✅ New product detected! Opening new product modal.");
            }
          }
        } catch (error) {
          safeToast("error", "Error checking barcode:", error);
          setScannerStatusMessage("❌ Error checking barcode. Please try again.");
          safeToast("error", "Failed to check barcode");
        }
        
        // Reset scanner status after a delay to show it's ready for next scan
        setTimeout(() => {
          setScannerStatusMessage("🔍 Scanner is ready and active - Scan any barcode to continue");
        }, 3000);
        break;
  
      default:
        safeToast("error", "Unknown scanner operation:", operation);
    }
  }
  
  
  
    // Event Handlers
    function handleAddSupplier(e) {
      e.preventDefault()
      // "Form submitted with data:", supplierFormData)
      handleCrudOperation("CREATE_SUPPLIER", supplierFormData)
    }
  
    function handleUpdateSupplier(e) {
      e.preventDefault()
      handleCrudOperation("UPDATE_SUPPLIER", editFormData)
    }

    function handleUpdateProduct(e) {
      e.preventDefault()
      handleCrudOperation("UPDATE_PRODUCT", editProductFormData)
    }
  
   function handleDeleteItem() {
    if (activeTab === "products") {
      handleCrudOperation("DELETE_PRODUCT", selectedItem);
    } else {
      handleCrudOperation("DELETE_SUPPLIER", selectedItem);
    }
  }
  
  
  
    // Removed handleSaveEntry since we're using modals now
  
    // Modal Actions
    function openSupplierModal() {
      clearSupplierForm()
      setShowSupplierModal(true)
    }
  
    function closeSupplierModal() {
      setShowSupplierModal(false)
      clearSupplierForm()
    }
  
    function openEditModal(item) {
      setSelectedItem(item)
      setEditFormData(item)
      setShowEditModal(true)
    }

    function openEditProductModal(product) {
      setSelectedItem(product)
      setEditProductFormData({
        product_name: product.product_name || "",
        category_id: product.category_id || "",
        barcode: product.barcode || "",
        description: product.description || "",
        srp: product.srp || product.unit_price || "",
        brand_id: product.brand_id || "",
        quantity: product.quantity || "",
        supplier_id: product.supplier_id || "",
        expiration: product.expiration || "",
        prescription: product.prescription || 0,
        bulk: product.bulk || 0
      })
      setShowEditProductModal(true)
    }
  
    function closeEditModal() {
      setShowEditModal(false)
      setSelectedItem(null)
      clearEditForm()
    }

    function closeEditProductModal() {
      setShowEditProductModal(false)
      setSelectedItem(null)
      setEditProductFormData({
        product_name: "",
        category_id: "",
        barcode: "",
        description: "",
        srp: "",
        brand_id: "",
        quantity: "",
        supplier_id: "",
        expiration: "",
        prescription: 0,
        bulk: 0
      })
    }
  
    function openDeleteModal(item) {
      setSelectedItem(item)
      setShowDeleteModal(true)
    }
  
    function closeDeleteModal() {
      setShowDeleteModal(false)
      setSelectedItem(null)
    }
  
    // New modal handlers for barcode scanning
    function closeUpdateStockModal() {
              setShowUpdateStockModal(false);
        setExistingProduct(null);
        setNewStockQuantity("");
        setNewStockExpiration("");
        // Reset bulk mode fields
        setNewStockBoxes("");
        setNewStockStripsPerBox("");
        setNewStockTabletsPerStrip("");
        setNewStockPiecesPerPack("");
        // Reset configuration mode
        setStockUpdateConfigMode("bulk");
      setEditSrpEnabled(false);
      setNewSrp("");
    }

    function openUpdateStockModal(product) {
      console.log("🔍 Opening Update Stock Modal with product data:", product);
      console.log("🔍 Product brand:", product.brand);
      console.log("🔍 Product category:", product.category);
      console.log("🔍 Product category_name:", product.category_name);
      console.log("🔍 Product brand_id:", product.brand_id);
      console.log("🔍 Product category_id:", product.category_id);
      console.log("🔍 All product keys:", Object.keys(product));
      
      // Use stored product_type from database, with fallback to auto-detection
      let productType = product.product_type || detectProductType(product.product_name); // Use stored value first
      
      // If no stored product_type, use auto-detection as fallback
      if (!product.product_type) {
        console.log("🔍 No stored product_type found, using auto-detection for:", product.product_name);
        
        // Primary detection: Check category name for medicine-related categories
        const medicineCategories = [
          "Medicine", "Pharmaceutical", "Drug", "Prescription", "OTC", 
          "Over-the-Counter", "Tablets", "Capsules", "Syrup", "Injection",
          "Vitamins", "Supplements", "Health", "Medical"
        ];
        const categoryName = product.category_name || product.category || "";
        
        if (medicineCategories.some(medCat => 
          categoryName.toLowerCase().includes(medCat.toLowerCase())
        )) {
          productType = "Medicine";
          console.log("🔍 Auto-detected Medicine product based on category:", categoryName);
        } else {
          // Secondary detection: Check product name for medicine keywords
          const medicineKeywords = [
            "tablet", "tablets", "capsule", "capsules", "syrup", "injection",
            "vitamin", "vitamins", "supplement", "medicine", "drug", "pharma",
            "paracetamol", "ibuprofen", "aspirin", "multivitamin", "calcium",
            "iron", "folic", "ascorbic", "vitamin c", "vitamin d", "vitamin b"
          ];
          const productName = (product.product_name || "").toLowerCase();
          
          if (medicineKeywords.some(keyword => productName.includes(keyword))) {
            productType = "Medicine";
            console.log("🔍 Auto-detected Medicine product based on product name:", product.product_name);
          } else {
            productType = "Non-Medicine";
            console.log("🔍 Auto-detected Non-Medicine product for:", product.product_name);
          }
        }
      } else {
        console.log("🔍 Using stored product_type:", product.product_type, "for:", product.product_name);
      }
      
      // Add product_type to the product object
      const productWithType = {
        ...product,
        product_type: productType
      };
      
      console.log("🔍 Final product type determined:", productType);
      setExistingProduct(productWithType);
      setNewStockQuantity("");
      setNewStockBoxes("");
      setNewStockStripsPerBox("");
      setNewStockTabletsPerStrip("");
      setNewStockPiecesPerPack("");
      setNewStockExpiration("");
      setNewSrp("");
      setEditSrpEnabled(false);
      
      // Set default configuration mode based on product data
      const hasBulkFields = product.boxes || product.strips_per_box || 
                            product.tablets_per_strip || 
                            product.pieces_per_pack;
      
      // Default to pieces mode for easier use and immediate button activation
      setStockUpdateConfigMode("pieces");
      
      setShowUpdateStockModal(true);
    }

    function closeNewProductModal() {
      setShowNewProductModal(false);
      setNewProductForm({
        product_name: "",
        category_id: "",
        product_type: "",
        configMode: "bulk", // Reset to default
        barcode: "",
        description: "",
        srp: "",
        brand_id: "",
        brand_search: "",
        supplier_id: "",
        expiration: "",
        date_added: new Date().toISOString().split('T')[0], // Auto-set current date
        batch: currentBatchNumber, // Keep current batch number instead of generating new one
        order_number: "",
        prescription: 0,
        bulk: 0,
        // Medicine fields
        boxes: "",
        strips_per_box: "",
        tablets_per_strip: "",
        total_tablets: "",
        // Non-Medicine fields
        pieces_per_pack: "",
        total_pieces: ""
      });
    }

    // Product Type Modal Functions
    function openProductTypeModal() {
      setShowProductTypeModal(true);
      // Pre-fill with existing values if any
      setProductTypeForm({
        product_type: newProductForm.product_type || "",
        configMode: newProductForm.configMode || "bulk", // Default to bulk mode
        boxes: newProductForm.boxes || "",
        strips_per_box: newProductForm.strips_per_box || "",
        tablets_per_strip: newProductForm.tablets_per_strip || "",
        total_tablets: newProductForm.total_tablets || "",
        pieces_per_pack: newProductForm.pieces_per_pack || "",
        total_pieces: newProductForm.total_pieces || ""
      });
    }

    function closeProductTypeModal() {
      setShowProductTypeModal(false);
      setProductTypeForm({
        product_type: "",
        configMode: "bulk", // Reset to default
        boxes: "",
        strips_per_box: "",
        tablets_per_strip: "",
        total_tablets: "",
        pieces_per_pack: "",
        total_pieces: ""
      });
    }

    function handleProductTypeInputChange(field, value) {
      setProductTypeForm(prev => {
        const updated = { ...prev, [field]: value };
        
        // Auto-calculate totals
        if (field === "boxes" || field === "strips_per_box" || field === "tablets_per_strip") {
          const boxes = parseInt(updated.boxes) || 0;
          const stripsPerBox = parseInt(updated.strips_per_box) || 0;
          const tabletsPerStrip = parseInt(updated.tablets_per_strip) || 0;
          updated.total_tablets = (boxes * stripsPerBox * tabletsPerStrip).toString();
        }
        
        if (field === "boxes" || field === "pieces_per_pack") {
          const boxes = parseInt(updated.boxes) || 0;
          const piecesPerPack = parseInt(updated.pieces_per_pack) || 0;
          updated.total_pieces = (boxes * piecesPerPack).toString();
        }
        
        return updated;
      });
    }

    function saveProductTypeConfiguration() {
      // Update the main form with the product type configuration
      setNewProductForm(prev => ({
        ...prev,
        product_type: productTypeForm.product_type,
        configMode: productTypeForm.configMode, // Save the configuration mode
        boxes: productTypeForm.boxes,
        strips_per_box: productTypeForm.strips_per_box,
        tablets_per_strip: productTypeForm.tablets_per_strip,
        total_tablets: productTypeForm.total_tablets,
        pieces_per_pack: productTypeForm.pieces_per_pack,
        total_pieces: productTypeForm.total_pieces
      }));
      
      closeProductTypeModal();
      safeToast("success", "Product type configuration saved!");
    }

    // Form handlers for new product modal
    function handleNewProductInputChange(field, value) {
      setNewProductForm(prev => {
        const updated = {
          ...prev,
          [field]: value
        };
        
        // Auto-calculate totals for bulk mode
        if (field === "boxes" || field === "strips_per_box" || field === "tablets_per_strip") {
          const boxes = parseInt(updated.boxes) || 0;
          const stripsPerBox = parseInt(updated.strips_per_box) || 0;
          const tabletsPerStrip = parseInt(updated.tablets_per_strip) || 0;
          updated.total_tablets = (boxes * stripsPerBox * tabletsPerStrip).toString();
        }
        
        if (field === "boxes" || field === "pieces_per_pack") {
          const boxes = parseInt(updated.boxes) || 0;
          const piecesPerPack = parseInt(updated.pieces_per_pack) || 0;
          updated.total_pieces = (boxes * piecesPerPack).toString();
        }
        
        return updated;
      });
    }

    // FIFO Functions
    async function getFifoStock(productId) {
      try {
        console.log("🔍 Calling get_fifo_stock API with product_id:", productId);
        const response = await handleApiCall("get_fifo_stock", { product_id: productId });
        console.log("📊 get_fifo_stock API response:", response);
        return response;
      } catch (error) {
        console.error("❌ Error getting FIFO stock:", error);
        safeToast("error", "Error getting FIFO stock:", error);
        return { success: false, error: error.message };
      }
    }

    // New function to get all batches across all products
    async function getAllBatches() {
      try {
        console.log("🔍 Calling get_all_batches API");
        const locationId = userRole.toLowerCase() === "admin" ? 2 : (userRole.toLowerCase() === "inventory manager" ? 1 : 2);
        const response = await handleApiCall("get_all_batches", { location_id: locationId });
        console.log("📊 get_all_batches API response:", response);
        return response;
      } catch (error) {
        console.error("❌ Error getting all batches:", error);
        safeToast("error", "Error getting all batches:", error);
        return { success: false, error: error.message };
      }
    }

    // New function to load products with their oldest batch information
    async function loadProductsWithOldestBatch() {
      try {
        // "🔄 Loading warehouse products with oldest batch info...");
        const locationId = userRole.toLowerCase() === "admin" ? 2 : (userRole.toLowerCase() === "inventory manager" ? 1 : 2);
const response = await handleApiCall("get_products_oldest_batch", { location_id: locationId, role: userRole, user_id: currentUser });
console.log("API response for products:", response);
        
        if (response.success && Array.isArray(response.data)) {
          // "✅ Products with oldest batch loaded:", response.data.length, "products");
          
          // Debug: Log first few products to check SRP and expiry data

          
          // Process the data to ensure proper field mapping for expiration
          const processedProducts = response.data.map(product => {
            // Handle different expiration date formats from backend - prioritize database values
            let expiration = null;
            
            // Priority 1: Check for expiration_date field from tbl_fifo_stock (most accurate)
            if (product.expiration_date && product.expiration_date !== '0000-00-00' && product.expiration_date !== '' && product.expiration_date !== null) {
              expiration = product.expiration_date;
            }
            // Priority 2: Check for oldest_batch_expiration from FIFO query
            else if (product.oldest_batch_expiration && product.oldest_batch_expiration !== '0000-00-00' && product.oldest_batch_expiration !== '' && product.oldest_batch_expiration !== null) {
              expiration = product.oldest_batch_expiration;
            }
            // Priority 3: Fallback to product expiration from tbl_product
            else if (product.expiration && product.expiration !== '0000-00-00' && product.expiration !== '' && product.expiration !== null) {
              expiration = product.expiration;
            }
            
            // Calculate days until expiry
            let daysUntilExpiry = null;
            if (expiration && expiration !== '0000-00-00' && expiration !== '' && expiration !== null) {
              const today = new Date();
              const expiryDate = new Date(expiration);
              const diffTime = expiryDate - today;
              daysUntilExpiry = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            }
            
            return {
              ...product,
              // Map expiration_date from database to expiration field expected by frontend
              expiration: expiration,
              // Ensure other batch fields are properly mapped from database
              oldest_batch_reference: product.batch_reference || product.oldest_batch_reference || null,
              oldest_batch_quantity: product.oldest_batch_quantity || 0,
              oldest_batch_expiration: expiration,
              oldest_batch_entry_date: product.entry_date || product.oldest_batch_entry_date || null,
              oldest_batch_unit_cost: product.unit_cost || product.oldest_batch_unit_cost || product.srp || 0,
              // Ensure first_batch_srp is preserved
              first_batch_srp: product.first_batch_srp || product.srp || 0,
              // Map entry_by from database to batch_entry_by field expected by frontend
              batch_entry_by: product.entry_by || product.batch_entry_by || "System",
              // Calculate days until expiry for display
              days_until_expiry: daysUntilExpiry,
            };
          });
          
          // "📦 Processed", processedProducts.length, "products with batch info");
          
          // Debug: Log processed products to check SRP data
          console.log("🔍 First product with batch data:", processedProducts[0]);
          console.log("🔍 Oldest batch quantity:", processedProducts[0]?.oldest_batch_quantity);
          console.log("🔍 Oldest batch SRP:", processedProducts[0]?.oldest_batch_srp);
          console.log("🔍 Product quantity:", processedProducts[0]?.quantity);
          console.log("🔍 Product SRP:", processedProducts[0]?.srp);
          
          return processedProducts;
        } else {
          console.warn("⚠️ Failed to load products with oldest batch, falling back to regular products");
          // Fallback to regular product loading
          const fallbackResponse = await handleApiCall("get_products", { location_id: locationId, role: userRole, user_id: currentUser });
          let productsArray = [];
          
          if (Array.isArray(fallbackResponse.data)) {
            productsArray = fallbackResponse.data;
          } else if (fallbackResponse.data && Array.isArray(fallbackResponse.data.data)) {
            productsArray = fallbackResponse.data.data;
          }
          
          // Filter for warehouse products and enrich with oldest batch info
const warehouseProducts = productsArray.filter(
  (product) => product.location_id === locationId
);
          
          // Enrich each product with oldest batch info
          const enrichedProducts = await Promise.all(
            warehouseProducts.map(async (product) => {
              try {
                const fifoResponse = await getFifoStock(product.product_id);
                if (fifoResponse.success && fifoResponse.data && fifoResponse.data.length > 0) {
                  const oldestBatch = fifoResponse.data[0]; // First batch is oldest
                  // Handle expiration date properly - prioritize database values from tbl_fifo_stock
                  let batchExpiration = null;
                  if (oldestBatch.expiration_date && oldestBatch.expiration_date !== '0000-00-00' && oldestBatch.expiration_date !== '' && oldestBatch.expiration_date !== null) {
                    batchExpiration = oldestBatch.expiration_date;
                  } else if (oldestBatch.expiration && oldestBatch.expiration !== '0000-00-00' && oldestBatch.expiration !== '' && oldestBatch.expiration !== null) {
                    batchExpiration = oldestBatch.expiration;
                  }
                  
                  // Calculate days until expiry
                  let daysUntilExpiry = null;
                  if (batchExpiration && batchExpiration !== '0000-00-00' && batchExpiration !== '' && batchExpiration !== null) {
                    const today = new Date();
                    const expiryDate = new Date(batchExpiration);
                    const diffTime = expiryDate - today;
                    daysUntilExpiry = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                  }
                  
                  return {
                    ...product,
                    // Map expiration data properly for frontend use
                    expiration: batchExpiration,
                    oldest_batch_reference: oldestBatch.batch_reference,
                    oldest_batch_quantity: oldestBatch.quantity,
                    oldest_batch_expiration: batchExpiration,
                    oldest_batch_entry_date: oldestBatch.entry_date,
                    oldest_batch_unit_cost: oldestBatch.unit_cost,
                    total_fifo_batches: fifoResponse.data.length,
                    // Use first_batch_srp from main API (original first batch by entry date)
                    first_batch_srp: product.first_batch_srp || oldestBatch.srp || product.srp || 0,
                    // Calculate days until expiry for display
                    days_until_expiry: daysUntilExpiry,
                  };
                } else {
                  // Handle expiration from product data
                  let productExpiration = null;
                  if (product.expiration_date && product.expiration_date !== '0000-00-00' && product.expiration_date !== '' && product.expiration_date !== null) {
                    productExpiration = product.expiration_date;
                  } else if (product.expiration && product.expiration !== '0000-00-00' && product.expiration !== '' && product.expiration !== null) {
                    productExpiration = product.expiration;
                  }
                  
                  // Calculate days until expiry
                  let daysUntilExpiry = null;
                  if (productExpiration && productExpiration !== '0000-00-00' && productExpiration !== '' && productExpiration !== null) {
                    const today = new Date();
                    const expiryDate = new Date(productExpiration);
                    const diffTime = expiryDate - today;
                    daysUntilExpiry = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                  }
                  
                  return {
                    ...product,
                    // Ensure expiration field is set even when no FIFO data
                    expiration: productExpiration,
                    oldest_batch_reference: null,
                    oldest_batch_quantity: 0,
                    oldest_batch_expiration: null,
                    oldest_batch_entry_date: null,
                    oldest_batch_unit_cost: product.srp || product.unit_price,
                    total_fifo_batches: 0,
                    // Add first batch SRP from product data
                    first_batch_srp: product.srp || product.unit_price || 0,
                    // Calculate days until expiry for display
                    days_until_expiry: daysUntilExpiry,
                  };
                }
              } catch (error) {
                safeToast("error", "Error enriching product", product.product_id, "with FIFO data:", error);
                
                // Handle expiration from product data on error
                let errorExpiration = null;
                if (product.expiration_date && product.expiration_date !== '0000-00-00' && product.expiration_date !== '' && product.expiration_date !== null) {
                  errorExpiration = product.expiration_date;
                } else if (product.expiration && product.expiration !== '0000-00-00' && product.expiration !== '' && product.expiration !== null) {
                  errorExpiration = product.expiration;
                }
                
                // Calculate days until expiry for error case
                let daysUntilExpiry = null;
                if (errorExpiration && errorExpiration !== '0000-00-00' && errorExpiration !== '' && errorExpiration !== null) {
                  const today = new Date();
                  const expiryDate = new Date(errorExpiration);
                  const diffTime = expiryDate - today;
                  daysUntilExpiry = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                }
                
                return {
                  ...product,
                  // Ensure expiration field is available even on error
                  expiration: errorExpiration,
                  // Add first batch SRP from product data on error
                  first_batch_srp: product.srp || product.unit_price || 0,
                  // Calculate days until expiry for display
                  days_until_expiry: daysUntilExpiry,
                };
              }
            })
          );
          
          return enrichedProducts;
        }
      } catch (error) {
        safeToast("error", "❌ Error loading products with oldest batch:", error);
        return [];
      }
    }

    // Function to refresh oldest batch data after stock changes
    async function refreshOldestBatchData() {
      // "🔄 Refreshing oldest batch data after stock changes...");
      try {
        const refreshedProducts = await loadProductsWithOldestBatch();
        const activeProducts = refreshedProducts.filter(
          (product) => (product.status || "").toLowerCase() !== "archived"
        );
        
        setInventoryData(activeProducts);
        updateStats("totalProducts", activeProducts.length);
        calculateWarehouseValue(activeProducts);
        calculateLowStockAndExpiring(activeProducts);
        // "✅ Oldest batch data refreshed successfully");
        
        return activeProducts;
      } catch (error) {
        safeToast("error", "❌ Error refreshing oldest batch data:", error);
        return [];
      }
    }

    // Function to refresh product quantities
    async function refreshProductQuantities() {
      // "🔄 Refreshing product quantities and expiration data...");
      try {
        const refreshedProducts = await loadProductsWithOldestBatch();
        const activeProducts = refreshedProducts.filter(
          (product) => (product.status || "").toLowerCase() !== "archived"
        );
        
        setInventoryData(activeProducts);
        await calculateNotifications(activeProducts);
        updateStats("totalProducts", activeProducts.length);
        calculateWarehouseValue(activeProducts);
        calculateLowStockAndExpiring(activeProducts);
        // "✅ Product quantities and expiration data refreshed successfully");
        
        return activeProducts;
      } catch (error) {
        safeToast("error", "❌ Error refreshing product quantities:", error);
        return [];
      }
    }

    // Function to load product quantities from tbl_product
    async function loadProductQuantities() {
      try {
        // "🔄 Loading product quantities from tbl_product...");
        const locationId = userRole.toLowerCase() === "admin" ? 2 : (userRole.toLowerCase() === "inventory manager" ? 1 : 2);
const response = await handleApiCall("get_product_quantities", { location_id: locationId, role: userRole, user_id: currentUser });
console.log("API response for product quantities:", response);
        
        if (response.success && Array.isArray(response.data)) {
          // "✅ Product quantities loaded:", response.data.length, "products");
          // "🔍 Sample product data:", response.data.slice(0, 3));
          return response.data;
        } else {
          console.warn("⚠️ Failed to load product quantities, falling back to regular products");
          // "⚠️ Response:", response);
          // Fallback to regular product loading
          const fallbackResponse = await handleApiCall("get_products", { location_id: locationId, role: userRole, user_id: currentUser });
          let productsArray = [];
          
          if (Array.isArray(fallbackResponse.data)) {
            productsArray = fallbackResponse.data;
          } else if (fallbackResponse.data && Array.isArray(fallbackResponse.data.data)) {
            productsArray = fallbackResponse.data.data;
          }
          
          // "🔄 Fallback products loaded:", productsArray.length, "products");
          return productsArray;
        }
      } catch (error) {
        safeToast("error", "❌ Error loading product quantities:", error);
        return [];
      }
    }

    async function getExpiringProducts(daysThreshold = 30) {
      try {
        const response = await handleApiCall("get_expiring_products", { days_threshold: daysThreshold });
        return response;
      } catch (error) {
        safeToast("error", "Error getting expiring products:", error);
        return { success: false, error: error.message };
      }
    }

    async function consumeStockFifo(productId, quantity, referenceNo = "", notes = "") {
      try {
        const response = await handleApiCall("consume_stock_fifo", { 
          product_id: productId, 
          quantity: quantity,
          reference_no: referenceNo,
          notes: notes,
          created_by: currentUser
        });
        return response;
      } catch (error) {
        safeToast("error", "Error consuming stock:", error);
        return { success: false, error: error.message };
      }
    }

    function openFifoModal(product) {
      setSelectedProductForFifo(product);
      setShowFifoModal(true);
      loadAllBatches(); // Load all batches instead of just selected product
    }

    function closeFifoModal() {
      setShowFifoModal(false);
      setSelectedProductForFifo(null);
      setFifoStockData([]);
      setAllBatchesData([]);
    }

      // Functions for expiring batch modal
  function openExpiringBatchModal(product) {
    setSelectedExpiringProduct(product);
    setShowExpiringBatchModal(true);
    // Load FIFO data for the product
    loadFifoStock(product.product_id);
  }

  function closeExpiringBatchModal() {
    setShowExpiringBatchModal(false);
    setSelectedExpiringProduct(null);
    setFifoStockData([]);
  }

    function openQuantityHistoryModal(product) {
      console.log("🔄 Opening quantity history modal for product:", product.product_name, "ID:", product.product_id);
      console.log("🔍 Product data when opening modal:", product);
      setSelectedProductForHistory(product);
      setShowQuantityHistoryModal(true);
      setShowCurrentFifoData(true); // Always start with FIFO view
      refreshProductData(product.product_id); // Load FIFO data
    }

    function closeQuantityHistoryModal() {
      setShowQuantityHistoryModal(false);
      setSelectedProductForHistory(null);
      setQuantityHistoryData([]);
    }

    async function loadQuantityHistory(productId) {
      console.log("🔄 Loading quantity history for product ID:", productId);
      try {
        const response = await handleApiCall("get_quantity_history", { product_id: productId });
        console.log("📊 Quantity history response:", response);
        
        if (response && response.success) {
          console.log("✅ Quantity history loaded successfully:", response.data?.length || 0, "entries");
          
          // Debug: Log each movement entry
          if (response.data && response.data.length > 0) {
            response.data.forEach((movement, index) => {
              console.log(`🔍 Movement ${index + 1}:`, {
                movement_id: movement.movement_id,
                movement_type: movement.movement_type,
                quantity_change: movement.quantity_change,
                remaining_quantity: movement.remaining_quantity,
                movement_date: movement.movement_date,
                reference_no: movement.reference_no,
                batch_reference: movement.batch_reference,
                created_by: movement.created_by
              });
            });
          } else {
            console.log("🔍 No movement history found for this product");
          }
          
          setQuantityHistoryData(response.data || []);
        } else {
          console.warn("Quantity history failed:", response?.message);
          // Don't show error toast - quantity history is not critical
          setQuantityHistoryData([]);
        }
      } catch (error) {
        console.error("Error loading quantity history:", error);
        // Don't show error toast - quantity history is not critical
        setQuantityHistoryData([]);
      }
    }

    // Function to refresh quantity history and FIFO stock data
    async function refreshProductData(productId) {
      console.log("🔄 Refreshing product data for ID:", productId);
      try {
        // Refresh quantity history (non-critical)
        console.log("🔄 Loading quantity history...");
        await loadQuantityHistory(productId);
        
        // Refresh FIFO stock data (critical)
        console.log("🔄 Loading FIFO stock data...");
        await loadFifoStock(productId);
        
        // Also refresh the main product list to update quantities
        console.log("🔄 Refreshing main product data...");
        await loadData("products");
        
        // Toggle to show current FIFO data instead of history
        setShowCurrentFifoData(true);
        
        console.log("✅ Product data refreshed successfully - Now showing current FIFO batches");
        safeToast("success", "Batch data refreshed successfully!");
      } catch (error) {
        console.error("❌ Error refreshing product data:", error);
        safeToast("error", "Failed to refresh batch data: " + error.message);
      }
    }





    async function loadFifoStock(productId) {
      console.log("🔄 Loading FIFO stock for product ID:", productId);
      const response = await getFifoStock(productId);
      console.log("📊 FIFO stock response:", response);
      if (response.success && Array.isArray(response.data)) {
        console.log("✅ FIFO data loaded successfully:", response.data.length, "batches");
        console.log("🔍 FIFO batches:", response.data);
        
        // Debug: Log each batch details
        response.data.forEach((batch, index) => {
          console.log(`🔍 Batch ${index + 1}:`, {
            batch_id: batch.batch_id,
            batch_reference: batch.batch_reference,
            available_quantity: batch.available_quantity,
            total_quantity: batch.total_quantity,
            expiration_date: batch.expiration_date,
            entry_date: batch.fifo_entry_date,
            batch_date: batch.batch_date
          });
        });
        
        setFifoStockData(response.data);
      } else {
        console.warn("⚠️ FIFO stock error:", response.message);
        console.warn("⚠️ Response data:", response.data);
        safeToast("error", "FIFO stock error:", response.message);
        safeToast("error", "Failed to load FIFO stock data: " + (response.message || "Unknown error"));
        setFifoStockData([]);
      }
    }

    // New function to load all batches across all products
    async function loadAllBatches() {
      console.log("🔄 Loading all batches across all products");
      const response = await getAllBatches();
      console.log("📊 All batches response:", response);
      if (response.success && Array.isArray(response.data)) {
        console.log("✅ All batches loaded successfully:", response.data.length, "total batches");
        console.log("🔍 All batches:", response.data);
        
        // Debug: Log each batch details
        response.data.forEach((batch, index) => {
          console.log(`🔍 Batch ${index + 1}:`, {
            product_name: batch.product_name,
            batch_id: batch.batch_id,
            batch_reference: batch.batch_reference,
            available_quantity: batch.available_quantity,
            total_quantity: batch.total_quantity,
            expiration_date: batch.expiration_date,
            entry_date: batch.fifo_entry_date,
            batch_date: batch.batch_date
          });
        });
        
        setAllBatchesData(response.data);
      } else {
        console.warn("⚠️ All batches error:", response.message);
        console.warn("⚠️ Response data:", response.data);
        safeToast("error", "All batches error:", response.message);
        safeToast("error", "Failed to load all batches data: " + (response.message || "Unknown error"));
        setAllBatchesData([]);
      }
    }

    // Handle update stock submission - CHANGED: Now adds to batch instead of immediate save
    // NOTE: This creates a NEW FIFO batch entry - tbl_product no longer stores quantity/srp
    async function handleAddStockToBatch() {
      // Validate SRP - REQUIRED for FIFO tracking
      const srpToUse = editSrpEnabled && newSrp ? parseFloat(newSrp) : parseFloat(existingProduct.srp || existingProduct.unit_price || 0);
      
      if (!srpToUse || srpToUse <= 0) {
        safeToast("error", "SRP is required for FIFO stock tracking. Please enable 'Edit SRP' and enter a valid price.");
        return;
      }
      
      // Validate expiration date - REQUIRED for FIFO tracking
      if (!newStockExpiration) {
        safeToast("error", "Expiration date is required for FIFO stock tracking.");
        return;
      }
      
      // Calculate quantity based on configuration mode
      let quantityToAdd = 0;
      
      // Check if user is using bulk mode (boxes and pieces configuration)
      const hasBulkConfiguration = (newStockBoxes && newStockBoxes > 0) || 
                                  (newStockStripsPerBox && newStockStripsPerBox > 0) || 
                                  (newStockTabletsPerStrip && newStockTabletsPerStrip > 0) ||
                                  (newStockPiecesPerPack && newStockPiecesPerPack > 0);
      
      // Check if user is using direct quantity input
      const hasDirectQuantity = newStockQuantity && newStockQuantity > 0;
      
      if (existingProduct.product_type === "Medicine") {
        // Medicine: calculate from boxes × strips × tablets
        if (stockUpdateConfigMode === "bulk") {
          const boxes = parseInt(newStockBoxes) || 0;
          const stripsPerBox = parseInt(newStockStripsPerBox) || 0;
          const tabletsPerStrip = parseInt(newStockTabletsPerStrip) || 0;
          
          if (boxes <= 0 || stripsPerBox <= 0 || tabletsPerStrip <= 0) {
            safeToast("error", "Please enter valid bulk quantities (boxes, strips per box, tablets per strip)");
            return;
          }
          quantityToAdd = boxes * stripsPerBox * tabletsPerStrip;
        } else {
          // Pieces mode for medicine
          if (!newStockQuantity || newStockQuantity <= 0) {
            safeToast("error", "Please enter a valid quantity of tablets");
            return;
          }
          quantityToAdd = parseInt(newStockQuantity);
        }
      } else if (existingProduct.product_type === "Non-Medicine") {
        // Non-Medicine: calculate from boxes × pieces per box
        if (stockUpdateConfigMode === "bulk") {
          const boxes = parseInt(newStockBoxes) || 0;
          const piecesPerPack = parseInt(newStockPiecesPerPack) || 0;
          
          if (boxes <= 0 || piecesPerPack <= 0) {
            safeToast("error", "Please enter valid bulk quantities (boxes, pieces per box)");
            return;
          }
          quantityToAdd = boxes * piecesPerPack;
        } else {
          // Pieces mode for non-medicine
          if (!newStockQuantity || newStockQuantity <= 0) {
            safeToast("error", "Please enter a valid quantity of pieces");
            return;
          }
          quantityToAdd = parseInt(newStockQuantity);
        }
      } else {
        // Unknown product type - try to detect configuration mode automatically
        if (hasBulkConfiguration) {
          // User is using bulk mode, calculate from boxes and pieces
          if (newStockBoxes && newStockPiecesPerPack) {
            // Non-medicine style bulk configuration
            const boxes = parseInt(newStockBoxes) || 0;
            const piecesPerPack = parseInt(newStockPiecesPerPack) || 0;
            
            if (boxes <= 0 || piecesPerPack <= 0) {
              safeToast("error", "Please enter valid bulk quantities (boxes, pieces per box)");
              return;
            }
            quantityToAdd = boxes * piecesPerPack;
          } else if (newStockBoxes && newStockStripsPerBox && newStockTabletsPerStrip) {
            // Medicine style bulk configuration
            const boxes = parseInt(newStockBoxes) || 0;
            const stripsPerBox = parseInt(newStockStripsPerBox) || 0;
            const tabletsPerStrip = parseInt(newStockTabletsPerStrip) || 0;
            
            if (boxes <= 0 || stripsPerBox <= 0 || tabletsPerStrip <= 0) {
              safeToast("error", "Please enter valid bulk quantities (boxes, strips per box, tablets per strip)");
              return;
            }
            quantityToAdd = boxes * stripsPerBox * tabletsPerStrip;
          } else {
            safeToast("error", "Please fill in all bulk configuration fields");
            return;
          }
        } else if (hasDirectQuantity) {
          // User is using direct quantity input
          quantityToAdd = parseInt(newStockQuantity);
        } else {
          // No valid configuration found
          safeToast("error", "Please configure quantity using bulk mode (boxes × pieces) or enter direct quantity");
          return;
        }
      }
      
      if (quantityToAdd <= 0) {
        safeToast("error", "Please enter a valid quantity");
        return;
      }

      // Add to temporary batch instead of saving to database
      // NOTE: This will create a NEW FIFO batch entry when saved
      const tempStockUpdate = {
        ...existingProduct,
        temp_id: Date.now(), // Unique temporary ID
        is_stock_update: true, // Flag to indicate this is a stock update, not a new product
        quantity_to_add: quantityToAdd,
        new_stock_boxes: newStockBoxes || null,
        new_stock_strips_per_box: newStockStripsPerBox || null,
        new_stock_tablets_per_strip: newStockTabletsPerStrip || null,
        new_stock_pieces_per_pack: newStockPiecesPerPack || null,
        stock_update_config_mode: stockUpdateConfigMode,
        expiration: newStockExpiration, // REQUIRED for FIFO
        new_srp: srpToUse, // REQUIRED for FIFO - this will be the SRP for this batch
        srp: srpToUse, // Also set main SRP field for consistency
        batch: currentBatchNumber, // Use the same batch number
        status: "pending_stock_update",
        created_at: new Date().toISOString(),
        entry_by: currentUser
      };

      setTemporaryProducts(prev => [...prev, tempStockUpdate]);
      
      // Close the modal and show success message
      closeUpdateStockModal();
      safeToast("success", `Stock update added to batch! (${quantityToAdd} ${existingProduct.product_type === "Medicine" ? "tablets" : "pieces"})`);
      
      console.log("✅ Stock update added to batch:", tempStockUpdate);
    }

    // Handle new product submission - Now adds to temporary storage
    // NOTE: tbl_product no longer stores quantity/srp - these will be stored in tbl_fifo_stock
    async function handleAddNewProduct(e) {
      e.preventDefault();
      
      // "🔄 Starting add product process...");
      // "📝 Form data:", newProductForm);
      // "🔍 Brand ID from form:", newProductForm.brand_id);
      // "🔍 Brand search from form:", newProductForm.brand_search);
      
      // Basic required fields validation
      if (!newProductForm.product_name || !newProductForm.category_id || !newProductForm.product_type || !newProductForm.srp) {
        safeToast("error", "Please fill in all required fields (Product Name, Category, Product Type, SRP)");
        // "❌ Basic validation failed - missing required fields");
        return;
      }
      
      // Validate SRP - REQUIRED for FIFO tracking
      if (!newProductForm.srp || parseFloat(newProductForm.srp) <= 0) {
        safeToast("error", "Valid SRP is required for FIFO stock tracking");
        return;
      }
      
      // Validate expiration date - REQUIRED for FIFO tracking
      if (!newProductForm.expiration) {
        safeToast("error", "Expiration date is required for FIFO stock tracking");
        return;
      }

      // Validate medicine-specific fields based on configuration mode
      if (newProductForm.product_type === "Medicine") {
        if (newProductForm.configMode === "bulk") {
          if (!newProductForm.boxes || !newProductForm.strips_per_box || !newProductForm.tablets_per_strip) {
            safeToast("error", "Please fill in all medicine-specific fields (Boxes, Strips per Box, Tablets per Strip)");
            // "❌ Medicine bulk mode validation failed - missing required fields");
            return;
          }
        } else if (newProductForm.configMode === "pieces") {
          if (!newProductForm.total_tablets) {
            safeToast("error", "Please enter the total number of tablets");
            // "❌ Medicine pieces mode validation failed - missing total tablets");
            return;
          }
        }
      }

      // Validate non-medicine-specific fields based on configuration mode
      if (newProductForm.product_type === "Non-Medicine") {
        if (newProductForm.configMode === "bulk") {
          if (!newProductForm.boxes || !newProductForm.pieces_per_pack) {
            safeToast("error", "Please fill in all non-medicine-specific fields (Boxes, Pieces per Box)");
            // "❌ Non-medicine bulk mode validation failed - missing required fields");
            return;
          }
        } else if (newProductForm.configMode === "pieces") {
          if (!newProductForm.total_pieces) {
            safeToast("error", "Please enter the total number of pieces");
            // "❌ Non-medicine pieces mode validation failed - missing total pieces");
            return;
          }
        }
      }

      // Add to temporary storage instead of database
      const tempProduct = {
        ...newProductForm,
        batch: currentBatchNumber, // Use the same batch number for all products
        temp_id: Date.now(), // Unique temporary ID
        status: "pending",
        created_at: new Date().toISOString(),
        bulk: newProductForm.configMode === "bulk" ? 1 : 0 // Set bulk flag based on config mode
      };

      setTemporaryProducts(prev => [...prev, tempProduct]);
      
      // Reset form for next product (keep same batch number and config mode)
      setNewProductForm({
        product_name: "",
        category_id: "",
        product_type: "",
        configMode: newProductForm.configMode || "bulk", // Preserve configuration mode
        barcode: "", // No auto-generated barcode
        description: "",
        srp: "",
        brand_id: "",
        brand_search: "",
        supplier_id: "",
        expiration: "",
        date_added: new Date().toISOString().split('T')[0],
        batch: newProductForm.batch || currentBatchNumber, // Keep user's batch input or current batch
        order_number: "",
        prescription: 0,
        bulk: 0,
        // Medicine fields
        boxes: "",
        strips_per_box: "",
        tablets_per_strip: "",
        total_tablets: "",
        // Non-Medicine fields
        pieces_per_pack: "",
        total_pieces: ""
      });

      safeToast("success", "Product added to batch! Add more products or save batch when ready.");
      // "✅ Product added to temporary storage:", tempProduct);
    }



    // New function to save batch as single entry - UPDATED to handle stock updates
    async function handleSaveBatch() {
      if (temporaryProducts.length === 0) {
        safeToast("error", "No products to save");
        return;
      }

      setLoading(true);

      try {
        console.log("🚀 Saving batch with", temporaryProducts.length, "items");
        console.log("🔍 Current user:", currentUser);
        console.log("🔍 Current batch number:", currentBatchNumber);
        
        // Separate new products and stock updates
        const newProducts = temporaryProducts.filter(p => !p.is_stock_update);
        const stockUpdates = temporaryProducts.filter(p => p.is_stock_update);
        
        console.log("📦 New products:", newProducts.length);
        console.log("📊 Stock updates:", stockUpdates.length);
        console.log("📋 All temporary products:", temporaryProducts);
        
        // Process stock updates first - creates NEW FIFO batch entries
        let stockUpdateSuccess = true;
        let failedUpdates = [];
        
        for (const stockUpdate of stockUpdates) {
          console.log("🔄 Processing stock update for:", stockUpdate.product_name);
          
          // Get the SRP for this FIFO batch
          const batchSrp = stockUpdate.new_srp || stockUpdate.srp || stockUpdate.unit_price || 0;
          
          console.log("📋 FIFO batch data:", {
            product_id: stockUpdate.product_id,
            quantity_to_add: stockUpdate.quantity_to_add,
            batch_reference: currentBatchNumber,
            expiration: stockUpdate.expiration,
            unit_cost: batchSrp,
            new_srp: batchSrp, // SRP for this specific FIFO batch
            entry_by: currentUser
          });
          
          // Validate data before making API call
          if (!stockUpdate.product_id || stockUpdate.product_id <= 0) {
            console.error("❌ Invalid product ID in stock update:", stockUpdate.product_id);
            safeToast("error", `Invalid product ID for ${stockUpdate.product_name}`);
            failedUpdates.push(stockUpdate.product_name);
            stockUpdateSuccess = false;
            continue;
          }
          
          if (!stockUpdate.quantity_to_add || stockUpdate.quantity_to_add <= 0) {
            console.error("❌ Invalid quantity in stock update:", stockUpdate.quantity_to_add);
            safeToast("error", `Invalid quantity for ${stockUpdate.product_name}`);
            failedUpdates.push(stockUpdate.product_name);
            stockUpdateSuccess = false;
            continue;
          }
          
          // Validate SRP - REQUIRED for FIFO
          if (!batchSrp || batchSrp <= 0) {
            console.error("❌ Invalid SRP in stock update:", batchSrp);
            safeToast("error", `Invalid SRP for ${stockUpdate.product_name}. SRP is required for FIFO tracking.`);
            failedUpdates.push(stockUpdate.product_name);
            stockUpdateSuccess = false;
            continue;
          }
          
          // Validate expiration date - REQUIRED for FIFO
          if (!stockUpdate.expiration) {
            console.error("❌ Missing expiration date in stock update");
            safeToast("error", `Missing expiration date for ${stockUpdate.product_name}. Expiration is required for FIFO tracking.`);
            failedUpdates.push(stockUpdate.product_name);
            stockUpdateSuccess = false;
            continue;
          }
          
          // Create new FIFO batch entry
          const response = await updateProductStock(
            stockUpdate.product_id,
            stockUpdate.quantity_to_add,
            currentBatchNumber,
            stockUpdate.expiration,
            batchSrp, // unit_cost
            batchSrp, // new_srp - this is the SRP for this FIFO batch
            currentUser
          );
          
          console.log("📊 FIFO batch creation response for", stockUpdate.product_name, ":", response);
          
          if (!response.success) {
            console.error("❌ Failed to create FIFO batch for:", stockUpdate.product_name);
            console.error("❌ Error details:", response.message);
            safeToast("error", `Failed to create FIFO batch for ${stockUpdate.product_name}: ${response.message}`);
            failedUpdates.push(stockUpdate.product_name);
            stockUpdateSuccess = false;
          } else {
            console.log("✅ FIFO batch created successfully for:", stockUpdate.product_name);
          }
        }
        
        // Process new products if any
        // NOTE: quantity and SRP are sent but will be stored in tbl_fifo_stock, NOT tbl_product
        if (newProducts.length > 0) {
          const batchData = {
            batch_reference: currentBatchNumber,
            batch_date: new Date().toISOString().split('T')[0],
            batch_time: new Date().toLocaleTimeString(),
            total_products: newProducts.length,
            total_quantity: newProducts.reduce((sum, p) => {
              const totalPieces = p.product_type === "Medicine" 
                ? parseInt(p.total_tablets || 0)
                : parseInt(p.total_pieces || 0);
              // Ensure we don&apos;t add NaN or invalid values
              return sum + (isNaN(totalPieces) || totalPieces <= 0 ? 1 : totalPieces);
            }, 0),
            total_value: newProducts.reduce((sum, p) => {
              const totalPieces = p.product_type === "Medicine" 
                ? parseInt(p.total_tablets || 0)
                : parseInt(p.total_pieces || 0);
              const validPieces = isNaN(totalPieces) || totalPieces <= 0 ? 1 : totalPieces;
              return sum + ((parseFloat(p.srp || 0) * validPieces));
            }, 0),
            location: "Warehouse",
            entry_by: currentUser,
            status: "active",
            products: newProducts.map(product => ({
              product_name: product.product_name,
              category_id: product.category_id,
              product_type: product.product_type,
              configMode: product.configMode || "bulk",
              barcode: product.barcode,
              description: product.description,
              unit_price: parseFloat(product.srp), // For FIFO batch entry
              srp: parseFloat(product.srp || 0), // For FIFO batch entry - NOT stored in tbl_product
              brand_id: product.brand_id || null, // Don't default to 1, let backend handle it
              brand_name: product.brand_search || null, // Pass brand name for new brand creation
              quantity: (() => {
                // For FIFO batch entry - NOT stored in tbl_product
                const qty = product.product_type === "Medicine" 
                  ? parseInt(product.total_tablets || 0)
                  : parseInt(product.total_pieces || 0);
                // Ensure quantity is a valid positive number
                return isNaN(qty) || qty <= 0 ? 1 : qty;
              })(),
              supplier_id: product.supplier_id || 1,
              expiration: product.expiration || null, // REQUIRED for FIFO batch entry
              prescription: product.prescription,
              bulk: product.bulk,
              boxes: product.boxes || null,
              strips_per_box: product.strips_per_box || null,
              tablets_per_strip: product.tablets_per_strip || null,
              total_tablets: product.total_tablets || null,
              pieces_per_pack: product.pieces_per_pack || null,
              total_pieces: product.total_pieces || null
            }))
          };

          console.log("🚀 Saving new products batch:", batchData);
          console.log("🔍 Product quantities:", newProducts.map(p => ({
            name: p.product_name,
            total_pieces: p.total_pieces,
            total_tablets: p.total_tablets,
            product_type: p.product_type,
            calculated_quantity: p.product_type === "Medicine" 
              ? parseInt(p.total_tablets || 0)
              : parseInt(p.total_pieces || 0)
          })));
          
          const response = await handleApiCall("add_batch_entry", batchData);
          
          if (!response.success) {
            safeToast("error", response.message || "Failed to save new products");
            setLoading(false);
            return;
          }
        }
        
        safeToast("success", `Batch saved successfully! ${stockUpdates.length} stock update(s) and ${newProducts.length} new product(s)`);
        
        // Clear temporary products and close modal
        setTemporaryProducts([]);
        setShowBatchEntryModal(false);
        
        // Generate new batch number for next batch
        const newBatchNumber = generateBatchRef();
        setCurrentBatchNumber(newBatchNumber);
        
        // Reset new product form with new batch number
        setNewProductForm(prev => ({
          ...prev,
          batch: newBatchNumber
        }));
        
        // Reload data to show new batch
        await loadData("products");
        await loadData("all");
        
        // Force refresh FIFO data for all products that had stock updates
        for (const stockUpdate of stockUpdates) {
          console.log("🔄 Refreshing FIFO data for updated product:", stockUpdate.product_name);
          await loadFifoStock(stockUpdate.product_id);
        }
        
        // Show appropriate success/error message
        if (stockUpdates.length > 0) {
          if (stockUpdateSuccess) {
            safeToast("success", `All stock updates completed successfully! New batch: ${newBatchNumber}`);
            console.log("✅ All stock updates completed successfully, new batch number generated:", newBatchNumber);
          } else {
            safeToast("error", `Some stock updates failed: ${failedUpdates.join(', ')}`);
            console.log("❌ Some stock updates failed:", failedUpdates);
          }
        } else {
          safeToast("success", `Batch saved successfully! New batch: ${newBatchNumber}`);
          console.log("✅ Batch saved successfully, new batch number generated:", newBatchNumber);
        }
      } catch (error) {
        console.error("❌ Error saving batch:", error);
        safeToast("error", "Failed to save batch: " + error.message);
      } finally {
        setLoading(false);
      }
    }

    // New function to remove product from temporary storage
    function removeTemporaryProduct(tempId) {
      setTemporaryProducts(prev => prev.filter(product => product.temp_id !== tempId));
      safeToast("success", "Product removed from batch");
    }

    // New function to open batch entry modal
    function openBatchEntryModal() {
      setShowBatchEntryModal(true);
    }

    // New function to close batch entry modal
    function closeBatchEntryModal() {
      setShowBatchEntryModal(false);
    }

    // New function to sync FIFO stock with product quantities
    async function syncFifoStock() {
      setLoading(true);
      try {
        // "🔄 Syncing FIFO stock with product quantities...");
        
        const response = await handleApiCall("sync_fifo_stock", {});
        
        if (response.success) {
          safeToast("success", response.message || "FIFO stock synced successfully!");
          
          // Refresh data to show updated quantities
          await loadData("products");
          await loadData("all");
          
          // "✅ FIFO stock sync completed");
        } else {
          safeToast("error", response.message || "Failed to sync FIFO stock");
        }
      } catch (error) {
        safeToast("error", "❌ Error syncing FIFO stock:", error);
        safeToast("error", "Failed to sync FIFO stock: " + error.message);
      } finally {
        setLoading(false);
      }
    }

    // Force sync all products with FIFO stock - fixes existing data inconsistencies
    async function forceSyncAllProducts() {
      setLoading(true);
      try {
        // "🔄 Force syncing all products with FIFO stock...");
        
        const response = await handleApiCall("force_sync_all_products", {});
        
        if (response.success) {
          safeToast("success", response.message || "All products force synced successfully!");
          
          // Refresh data to show updated quantities
          await loadData("products");
          await loadData("all");
          
          // "✅ Force sync completed");
        } else {
          safeToast("error", response.message || "Failed to force sync all products");
        }
      } catch (error) {
        safeToast("error", "Failed to force sync all products: " + error.message);
        safeToast("error", "Failed to force sync all products: " + error.message);
      } finally {
        setLoading(false);
      }
    }

    // Clean up duplicate products that were incorrectly created during transfers
    async function cleanupDuplicateTransferProducts() {
      setLoading(true);
      try {
        // "🧹 Cleaning up duplicate transfer products...");
        
        const response = await handleApiCall("cleanup_duplicate_transfer_products", {});
        
        if (response.success) {
          safeToast("success", response.message || "Duplicate transfer products cleaned up successfully!");
          
          // Refresh data to show cleaned up products
          await loadData("products");
          await loadData("all");
          
          // "✅ Cleanup completed");
        } else {
          safeToast("error", response.message || "Failed to cleanup duplicate transfer products");
        }
      } catch (error) {
        safeToast("error", "Failed to cleanup duplicate transfer products: " + error.message);
        safeToast("error", "Failed to cleanup duplicate transfer products: " + error.message);
      } finally {
        setLoading(false);
      }
    }


    // New function to handle batch duplication
    async function handleDuplicateBatches(productId, batchIds = [22, 23]) {
      setLoading(true);
      
      try {
        const response = await duplicateProductBatches(productId, batchIds);
        
        if (response && response.success) {
          safeToast("success", response.message || "Product batches duplicated successfully!");
          
          // Refresh the products list to show the new batch entries
          await loadData("products");
          
        } else {
          safeToast("error", response?.message || "Failed to duplicate product batches");
        }
        
      } catch (error) {
        safeToast("error", "Error duplicating batches:", error);
        safeToast("error", "An error occurred while duplicating product batches");
      } finally {
        setLoading(false);
      }
    }

    // New function to add quantity to existing product (prevents duplicates)
    async function addQuantityToExistingProduct(productName, category, quantity, unitCost, srp, expiration, batchReference) {
      setLoading(true);
      
      try {
        const productData = {
          product_name: productName,
          category: category,
          quantity: parseInt(quantity),
          unit_cost: parseFloat(unitCost),
          srp: parseFloat(srp || 0),
          expiration: expiration || null,
          batch_reference: batchReference || generateBatchRef(),
          supplier_id: 1, // Default supplier
          location_id: 2  // Warehouse location
        };

        // "🔄 Adding quantity to existing product:", productData);

        const response = await handleApiCall("add_quantity_to_product", productData);
        // "📡 API Response:", response);
        
        if (response.success) {
          safeToast("success", "Quantity added successfully to existing product!");
          
          // Reload data to show updated quantities
          await loadData("products");
          await loadData("all");
          
        } else {
          safeToast("error", response.message || "Failed to add quantity to product");
        }
      } catch (error) {
        safeToast("error", "Failed to add quantity: " + error.message);
        safeToast("error", "Failed to add quantity: " + error.message);
      } finally {
        setLoading(false);
      }
    }


  
    // Component Lifecycle
    useEffect(() => {
      // Fetch warehouse KPIs on mount
      async function fetchWarehouseKPIs() {
        try {
          const response = await handleApiCall("get_warehouse_kpis", { location: "warehouse" });
          console.log("📊 Warehouse KPIs response:", response);
          
          if (response && response.success !== false && response !== null) {
            setStats((prev) => ({
              ...prev,
              totalProducts: response.totalProducts ?? prev.totalProducts,
              totalSuppliers: response.totalSuppliers ?? prev.totalSuppliers,
              warehouseValue: response.warehouseValue ?? prev.warehouseValue,
              totalQuantity: response.totalQuantity ?? prev.totalQuantity,
              lowStockItems: response.lowStockItems ?? prev.lowStockItems,
              expiringSoon: response.expiringSoon ?? prev.expiringSoon,
            }));
          } else {
            console.warn("⚠️ Warehouse KPIs failed:", response?.message);
            // Don't show error toast - KPIs are not critical, products will still load
          }
        } catch (error) {
          console.error("❌ Error fetching warehouse KPIs:", error);
          // Don't show error toast - KPIs are not critical, products will still load
        }
      }
      fetchWarehouseKPIs();
      loadData("all");
      
      // Auto-start scanner when component mounts (if enabled in settings)
      console.log("🚀 Auto-starting scanner...");
      setScannerActive(settings.barcodeScanning);
      setScannerStatusMessage(settings.barcodeScanning ? "🔍 Scanner is ready and active - Scan any barcode to continue" : "🔍 Barcode scanning is disabled in settings");
    }, [])

    // Auto-refresh for notifications (every 30 seconds for warehouse)
    useEffect(() => {
      const interval = setInterval(async () => {
        if (!loading && inventoryData.length > 0 && notifications) {
          // "🔄 Auto-refreshing warehouse notifications...");
          const previousExpiringCount = notifications.expiring?.length || 0;
          const previousExpiredCount = notifications.expired?.length || 0;
          const previousLowStockCount = notifications.lowStock?.length || 0;
          
          // Recalculate notifications
          await calculateNotifications(inventoryData);
          
          // Check for new expired products
          if (notifications.expired.length > previousExpiredCount && settings.expiryAlerts) {
            const newExpiredProducts = notifications.expired.length - previousExpiredCount;
            safeToast("error", `🚨 ${newExpiredProducts} product(s) have EXPIRED! Check immediately!`);
          }
          
          // Check for new expiring products
          if (notifications.expiring.length > previousExpiringCount && settings.expiryAlerts) {
            const newExpiringProducts = notifications.expiring.length - previousExpiringCount;
            safeToast("warning", `⚠️ ${newExpiringProducts} product(s) expiring within ${settings.expiryWarningDays} days!`);
          }
          
          // Check for new low stock products
          if (notifications.lowStock.length > previousLowStockCount && settings.lowStockAlerts) {
            const newLowStockProducts = notifications.lowStock.length - previousLowStockCount;
            safeToast("warning", `📦 ${newLowStockProducts} product(s) now have low stock!`);
          }
        }
      }, 30000); // 30 seconds for warehouse

      return () => clearInterval(interval);
    }, [loading, inventoryData.length, notifications?.expiring?.length || 0, notifications?.expired?.length || 0, notifications?.lowStock?.length || 0, settings.expiryAlerts, settings.lowStockAlerts, settings.expiryWarningDays]);

    // Update scanner state when barcode scanning setting changes
    useEffect(() => {
      setScannerActive(settings.barcodeScanning);
      setScannerStatusMessage(settings.barcodeScanning ? "🔍 Scanner is ready and active - Scan any barcode to continue" : "🔍 Barcode scanning is disabled in settings");
    }, [settings.barcodeScanning]);

    // Update form batch number when currentBatchNumber changes (only if form is open)
    useEffect(() => {
      if (showNewProductModal) {
        setNewProductForm(prev => ({
          ...prev,
          batch: currentBatchNumber
        }));
      }
    }, [currentBatchNumber, showNewProductModal]);
  
    // Debug useEffect to track categoriesData changes
    useEffect(() => {
      // "🔄 categoriesData changed:", categoriesData);
      // "🔄 categoriesData length:", categoriesData.length);
      if (categoriesData.length > 0) {
        // "🔄 First category:", categoriesData[0]);
        // "🔄 All categories:", categoriesData.map(cat => cat.category_name));
      }
    }, [categoriesData])

    function calculateLowStockAndExpiring(products) {
      // Low stock threshold can also be made dynamic if needed
      const LOW_STOCK_THRESHOLD = 10;

      const now = new Date();

      // Low stock: using settings threshold
      const lowStockCount = products.filter(
        (product) => isStockLow(Number(product.total_quantity || product.product_quantity || product.quantity))
      ).length;

      // Expiring soon: using settings threshold
      const expiringSoonCount = products.filter((product) => {
        if (!product.expiration || !settings.expiryAlerts) return false;
        return isProductExpiringSoon(product.expiration);
      }).length;

      setStats((prev) => ({
        ...prev,
        lowStockItems: lowStockCount,
        expiringSoon: expiringSoonCount,
      }));

      // Check for auto-reorder if enabled
      checkAutoReorder(products);
    }

    // Auto-reorder functionality
    function checkAutoReorder(products) {
      if (!settings.autoReorder) return;
      
      const lowStockProducts = products.filter(product => {
        const quantity = parseInt(product.total_quantity || product.quantity || product.product_quantity || 0);
        return isStockLow(quantity);
      });
      
      if (lowStockProducts.length > 0) {
        const productNames = lowStockProducts.map(p => p.product_name).join(', ');
        safeToast("warning", `🔄 Auto-reorder: ${lowStockProducts.length} product(s) need restocking: ${productNames}`);
        
        // Here you could trigger an API call to create purchase orders
        // or send notifications to suppliers
        // "Auto-reorder triggered for products:", lowStockProducts);
      }
    }

  

    return (
      <div className="min-h-screen w-full" style={{ backgroundColor: theme.bg.primary }}>
        <NotificationSystem 
          products={inventoryData} 
          onAlertCountChange={setAlertCount}
        />
        {/* Header */}
        <div className="mb-6 p-6">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-2xl font-bold mb-2" style={{ color: theme.text.primary }}>Warehouse</h1>
              <p style={{ color: theme.text.secondary }}>Manage your inventory efficiently and effectively</p>
            </div>
            
            {/* Notification Bell */}
            <div className="relative notification-dropdown">
              <button
                onClick={() => setShowNotifications(!showNotifications)}
                className="relative p-2 rounded-full hover:bg-opacity-10 transition-colors"
                style={{ 
                  backgroundColor: 'transparent',
                  '--hover-bg': theme.bg.hover
                }}
                onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.hover}
                onMouseLeave={(e) => e.target.style.backgroundColor = 'transparent'}
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
                      {(notifications?.expiring?.length || 0) + (notifications?.expired?.length || 0) + (notifications?.lowStock?.length || 0) + (notifications?.outOfStock?.length || 0)} alerts
                    </p>
                  </div>

                  <div className="max-h-80 overflow-y-auto">
                    {/* Expired Products */}
                    {(notifications?.expired?.length || 0) > 0 && (
                      <div className="p-4 border-b" style={{ borderColor: theme.border.light }}>
                        <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.danger }}>
                          <AlertTriangle className="h-4 w-4 mr-2" />
                          EXPIRED ({notifications?.expired?.length || 0})
                        </h4>
                        {(notifications?.expired || []).slice(0, 5).map((product, index) => (
                          <div key={index} className="flex justify-between items-center py-1">
                            <span className="text-sm" style={{ color: theme.text.primary }}>{product.product_name}</span>
                            <span className="text-xs px-2 py-1 rounded" style={{ 
                              backgroundColor: theme.colors.danger + '20', 
                              color: theme.colors.danger 
                            }}>
                              EXPIRED {Math.abs(Math.ceil((new Date(product.expiration) - new Date()) / (1000 * 60 * 60 * 24)))} days ago
                            </span>
                          </div>
                        ))}
                        {(notifications?.expired?.length || 0) > 5 && (
                          <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                            +{(notifications?.expired?.length || 0) - 5} more...
                          </p>
                        )}
                      </div>
                    )}

                    {/* Expiring Products */}
                    {(notifications?.expiring?.length || 0) > 0 && (
                      <div className="p-4 border-b" style={{ borderColor: theme.border.light }}>
                        <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.warning }}>
                          <Clock className="h-4 w-4 mr-2" />
                          Expiring Soon ({(notifications?.expiring?.length || 0)})
                        </h4>
                        {(notifications?.expiring || []).slice(0, 5).map((product, index) => (
                          <div 
                            key={index} 
                            className="flex justify-between items-center py-2 cursor-pointer rounded px-3 transition-all duration-200 hover:bg-opacity-10"
                            style={{ 
                              backgroundColor: 'transparent',
                              color: theme.text.primary
                            }}
                            onMouseEnter={(e) => {
                              e.target.style.backgroundColor = theme.colors.warning + '20';
                              e.target.style.color = theme.colors.warning;
                            }}
                            onMouseLeave={(e) => {
                              e.target.style.backgroundColor = 'transparent';
                              e.target.style.color = theme.text.primary;
                            }}
                            onClick={() => openExpiringBatchModal(product)}
                          >
                            <div className="flex flex-col">
                              <span className="text-sm" style={{ color: 'inherit' }}>{product.product_name}</span>
                              {product.expiring_batch && (
                                <span className="text-xs" style={{ color: 'inherit', opacity: 0.8 }}>
                                  Batch {product.expiring_batch.batch_number || product.expiring_batch.batch_id} ({product.expiring_batch.quantity} units)
                                </span>
                              )}
                            </div>
                            <span className="text-xs px-2 py-1 rounded" style={{ 
                              backgroundColor: theme.colors.warning + '20', 
                              color: theme.colors.warning 
                            }}>
                              {product.expiring_batch ? product.expiring_batch.days_until_expiry : Math.ceil((new Date(product.earliest_expiration) - new Date()) / (1000 * 60 * 60 * 24))} days (Alert: {settings.expiryWarningDays}d)
                            </span>
                          </div>
                        ))}
                        {(notifications?.expiring?.length || 0) > 5 && (
                          <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                            +{(notifications?.expiring?.length || 0) - 5} more...
                          </p>
                        )}
                      </div>
                    )}

                    {/* Low Stock Products */}
                    {(notifications?.lowStock?.length || 0) > 0 && (
                      <div className="p-4 border-b" style={{ borderColor: theme.border.light }}>
                        <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.warning }}>
                          <AlertCircle className="h-4 w-4 mr-2" />
                          Low Stock ({(notifications?.lowStock?.length || 0)})
                        </h4>
                        {(notifications?.lowStock || []).slice(0, 5).map((product, index) => (
                          <div 
                            key={index} 
                            className="flex justify-between items-center py-2 cursor-pointer rounded px-3 transition-all duration-200"
                            style={{ 
                              backgroundColor: 'transparent',
                              color: theme.text.primary
                            }}
                            onMouseEnter={(e) => {
                              e.target.style.backgroundColor = theme.colors.warning + '20';
                              e.target.style.color = theme.colors.warning;
                            }}
                            onMouseLeave={(e) => {
                              e.target.style.backgroundColor = 'transparent';
                              e.target.style.color = theme.text.primary;
                            }}
                            onClick={() => openExpiringBatchModal(product)}
                          >
                            <span className="text-sm" style={{ color: 'inherit' }}>{product.product_name}</span>
                            <span className="text-xs px-2 py-1 rounded" style={{ 
                              backgroundColor: theme.colors.warning + '20', 
                              color: theme.colors.warning 
                            }}>
                              {product.quantity} left 
                            </span>
                          </div>
                        ))}
                        {(notifications?.lowStock?.length || 0) > 5 && (
                          <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                            +{(notifications?.lowStock?.length || 0) - 5} more...
                          </p>
                        )}
                      </div>
                    )}

                    {/* Out of Stock Products */}
                    {(notifications?.outOfStock?.length || 0) > 0 && (
                      <div className="p-4">
                        <h4 className="font-medium mb-2 flex items-center" style={{ color: theme.colors.danger }}>
                          <Package className="h-4 w-4 mr-2" />
                          Out of Stock ({(notifications?.outOfStock?.length || 0)})
                        </h4>
                        {(notifications?.outOfStock || []).slice(0, 5).map((product, index) => (
                          <div 
                            key={index} 
                            className="flex justify-between items-center py-2 cursor-pointer rounded px-3 transition-all duration-200"
                            style={{ 
                              backgroundColor: 'transparent',
                              color: theme.text.primary
                            }}
                            onMouseEnter={(e) => {
                              e.target.style.backgroundColor = theme.colors.danger + '20';
                              e.target.style.color = theme.colors.danger;
                            }}
                            onMouseLeave={(e) => {
                              e.target.style.backgroundColor = 'transparent';
                              e.target.style.color = theme.text.primary;
                            }}
                            onClick={() => openExpiringBatchModal(product)}
                          >
                            <span className="text-sm" style={{ color: 'inherit' }}>{product.product_name}</span>
                            <span className="text-xs px-2 py-1 rounded" style={{ 
                              backgroundColor: theme.colors.danger + '20', 
                              color: theme.colors.danger 
                            }}>
                              0 stock
                            </span>
                          </div>
                        ))}
                        {(notifications?.outOfStock?.length || 0) > 5 && (
                          <p className="text-xs mt-2" style={{ color: theme.text.secondary }}>
                            +{(notifications?.outOfStock?.length || 0) - 5} more...
                          </p>
                        )}
                      </div>
                    )}

                    {/* No Notifications */}
                    {((notifications?.expiring?.length || 0) + (notifications?.expired?.length || 0) + (notifications?.lowStock?.length || 0) + (notifications?.outOfStock?.length || 0)) === 0 && (
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

        {/* Status Bar */}
        <div className="rounded-lg border mb-6" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.default }}>
          <div className="p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center space-x-6">
                <div className="flex items-center space-x-2">
                  <MapPin className="h-4 w-4" style={{ color: theme.colors.accent }} />
                  <span className="text-sm font-medium" style={{ color: theme.text.primary }}>Current Location:</span>
                  <span className="inline-block px-2 py-0.5 text-xs font-medium rounded" style={{ backgroundColor: theme.colors.accent + '20', color: theme.colors.accent }}>
                    {currentLocation.toUpperCase()}
                  </span>
                </div>
                <div className="flex items-center space-x-2">
                  <Scan className="h-4 w-4" style={{ color: theme.colors.success }} />
                  <span className="text-sm font-medium" style={{ color: theme.text.primary }}>Scanner:</span>
                  <span
                    className="inline-block px-2 py-0.5 text-xs font-medium rounded"
                    style={{ backgroundColor: theme.colors.success + '20', color: theme.colors.success }}
                  >
                    ACTIVE
                  </span>
                </div>
                <div className="flex items-center space-x-2">
                  <User className="h-4 w-4" style={{ color: theme.colors.info }} />
                  <span className="text-sm font-medium" style={{ color: theme.text.primary }}>Employee:</span>
                  <div className="flex items-center space-x-1">
                    <input
                      type="text"
                      value={currentUser}
                      onChange={(e) => {
                        const newName = e.target.value;
                        setCurrentUser(newName);
                        localStorage.setItem('warehouse_employee', newName);
                      }}
                      placeholder="Enter your name"
                      className="px-2 py-0.5 text-xs border rounded focus:outline-none focus:ring-1 w-24"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary
                      }}
                      title="Click to edit your name for tracking purposes"
                    />
                    {currentUser !== (userRole.toLowerCase() === 'admin' ? "admin" : "inventory") && (
                      <button
                        onClick={() => {
                          const defaultUser = userRole.toLowerCase() === 'admin' ? "admin" : "inventory";
                          setCurrentUser(defaultUser);
                          localStorage.removeItem('warehouse_employee');
                        }}
                        className="text-xs px-1"
                        style={{ color: theme.colors.danger }}
                        title="Reset to default"
                      >
                        ×
                      </button>
                    )}
                  </div>
                </div>
              </div>
              <div className="flex items-center space-x-4">
                <div className="flex items-center space-x-2" style={{ color: theme.colors.success }}>
                  <Camera className="h-4 w-4" />
                  <span className="text-sm font-medium">Scanner Active</span>
                </div>
                <div className="text-sm max-w-md" style={{ color: theme.text.secondary }}>
                  {scannerStatusMessage}
                </div>

                {/* Manual Entry Section - Smart Detection: Barcode or Product Name */}
                <div className="flex items-center space-x-2">
                  <input
                    type="text"
                    placeholder="Enter barcode or product name..."
                    value={productNameInput}
                    onChange={(e) => setProductNameInput(e.target.value)}
                    onKeyPress={(e) => {
                      if (e.key === 'Enter') {
                        handleProductNameCheck(productNameInput);
                      }
                    }}
                    className="px-3 py-1 text-sm border rounded focus:outline-none focus:ring-1 w-56"
                    style={{ 
                      borderColor: theme.border.default,
                      backgroundColor: theme.bg.secondary,
                      color: theme.text.primary,
                      focusRingColor: theme.colors.accent
                    }}
                    disabled={isCheckingProductName}
                    title="Type a barcode or product name and press Enter or click Check"
                  />
                  <button
                    onClick={() => handleProductNameCheck(productNameInput)}
                    disabled={isCheckingProductName || !productNameInput.trim()}
                    className="px-3 py-1 rounded flex items-center text-sm font-medium transition-colors"
                    style={{ 
                      backgroundColor: isCheckingProductName || !productNameInput.trim() 
                        ? theme.bg.muted 
                        : theme.colors.warning,
                      color: isCheckingProductName || !productNameInput.trim() 
                        ? theme.text.muted 
                        : 'white',
                      cursor: isCheckingProductName || !productNameInput.trim() 
                        ? 'not-allowed' 
                        : 'pointer'
                    }}
                    title="Smart detection: automatically checks if input is barcode or product name"
                  >
                    <Search className="h-4 w-4 mr-1" />
                    {isCheckingProductName ? 'Checking...' : 'Check'}
                  </button>
                </div>
                <div className="text-sm max-w-md" style={{ color: theme.text.secondary }}>
                  {productNameStatusMessage}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="px-6 mb-6">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            {[
              { title: "Total Products", value: stats.totalProducts, icon: Package, color: "blue" },
              { title: "Product Qty", value: (stats.totalProductQuantity || 0).toLocaleString(), icon: Package, color: "indigo" },
              { title: "Total Suppliers", value: stats.totalSuppliers, icon: User, color: "orange" },
              {
                title: "Warehouse Value",
                value: `₱${stats.warehouseValue.toLocaleString()}`,
                icon: DollarSign,
                color: "purple",
              },
            ].map((stat, index) => (
              <div key={index} className="rounded-lg border p-4 flex items-center" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.default }}>
                <stat.icon className="h-6 w-6" style={{ color: theme.colors.accent }} />
                <div className="ml-3">
                  <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>{stat.title}</p>
                  <p className="text-lg font-bold" style={{ color: theme.text.primary }}>{stat.value}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
   
        {/* Search and Filter Bar */}
        <div className="px-6 mb-6">
          <div className="rounded-lg border p-4" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.default }}>
          <div className="flex flex-col md:flex-row gap-4">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4" style={{ color: theme.text.muted }} />
                <input
                  type="text"
                  placeholder={`Search ${activeTab}...`}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="pl-10 pr-4 py-2 w-full border rounded-md focus:outline-none focus:ring-2"
                  style={{ 
                    borderColor: theme.border.default,
                    backgroundColor: theme.bg.secondary,
                    color: theme.text.primary,
                    focusRingColor: theme.colors.accent,
                    '::placeholder': {
                      color: theme.text.muted
                    }
                  }}
                />
              </div>
            </div>
            {activeTab === "products" && (
              <div className="w-full md:w-48">
                <select
                  value={filterOptions.category}
                  onChange={(e) => {
                    const selectedCategory = e.target.value;
                    setFilterOptions((prev) => ({ ...prev, category: selectedCategory }));
                  }}
                  className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                  style={{ 
                    borderColor: theme.border.default,
                    backgroundColor: theme.bg.secondary,
                    color: theme.text.primary,
                    focusRingColor: theme.colors.accent
                  }}
                >
                  <option value="">All Categories</option>
                  {categoriesData.map((category) => (
                    <option key={category.category_id} value={category.category_name}>
                      {category.category_name}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
        </div>
        </div>
            
        {/* Tabs for Products and Suppliers */}
        <div className="px-6">
          <div className="border-b" style={{ borderColor: theme.border.default }}>
            <nav className="-mb-px flex">
              <button
                onClick={() => setActiveTab("products")}
                className="py-2 px-4 border-b-2 font-medium text-sm"
                style={{
                  borderColor: activeTab === "products" ? theme.colors.accent : 'transparent',
                  color: activeTab === "products" ? theme.colors.accent : theme.text.muted,
                  borderBottomColor: activeTab === "products" ? theme.colors.accent : 'transparent'
                }}
              >
                Products ({inventoryData.length})
              </button>
              <button
                onClick={() => setActiveTab("suppliers")}
                className="py-2 px-4 border-b-2 font-medium text-sm"
                style={{
                  borderColor: activeTab === "suppliers" ? theme.colors.accent : 'transparent',
                  color: activeTab === "suppliers" ? theme.colors.accent : theme.text.muted,
                  borderBottomColor: activeTab === "suppliers" ? theme.colors.accent : 'transparent'
                }}
              >
                Suppliers ({suppliersData.length})
              </button>
            </nav>
          </div>
  
  <div className="p-2">
    {activeTab === "products" && (
      <div className="rounded-3xl shadow-xl" style={{ backgroundColor: theme.bg.card, boxShadow: `0 25px 50px ${theme.shadow}` }}>
        <div className="px-4 py-3 border-b" style={{ borderColor: theme.border.default }}>
          <div className="flex justify-between items-center">
            <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>Products</h3>
            <div className="flex items-center space-x-3">
              <div className="flex space-x-2">
                <button
                  onClick={() => setFilterOptions(prev => ({ ...prev, stockStatus: 'all' }))}
                  className="px-3 py-1 text-xs rounded-md transition-colors"
                  style={{
                    backgroundColor: filterOptions.stockStatus === 'all' ? theme.colors.accent + '20' : theme.bg.hover,
                    color: filterOptions.stockStatus === 'all' ? theme.colors.accent : theme.text.secondary
                  }}
                >
                  All
                </button>
                <button
                  onClick={() => setFilterOptions(prev => ({ ...prev, stockStatus: 'low' }))}
                  className="px-3 py-1 text-xs rounded-md transition-colors"
                  style={{
                    backgroundColor: filterOptions.stockStatus === 'low' ? theme.colors.warning + '20' : theme.bg.hover,
                    color: filterOptions.stockStatus === 'low' ? theme.colors.warning : theme.text.secondary
                  }}
                >
                  Low Stock
                </button>
                <button
                  onClick={() => setFilterOptions(prev => ({ ...prev, stockStatus: 'out' }))}
                  className="px-3 py-1 text-xs rounded-md transition-colors"
                  style={{
                    backgroundColor: filterOptions.stockStatus === 'out' ? theme.colors.danger + '20' : theme.bg.hover,
                    color: filterOptions.stockStatus === 'out' ? theme.colors.danger : theme.text.secondary
                  }}
                >
                  Out of Stock
                </button>
              </div>
              <div className="flex space-x-2">
                <button
                  onClick={syncFifoStock}
                  className="p-2 rounded-md transition-colors"
                  style={{ 
                    color: theme.colors.accent,
                    backgroundColor: 'transparent'
                  }}
                  title="Sync FIFO Stock with Product Quantities"
                >
                  <Package className="h-4 w-4" />
                </button>
                <button
                  onClick={forceSyncAllProducts}
                  className="p-2 text-red-500 hover:text-red-700 hover:bg-red-100 rounded-md transition-colors"
                  title="Force Sync All Products with FIFO Stock"
                >
                  <RefreshCw className="h-4 w-4" />
                </button>
                <button
                  onClick={cleanupDuplicateTransferProducts}
                  className="p-2 text-orange-500 hover:text-orange-700 hover:bg-orange-100 rounded-md transition-colors"
                  title="Clean Up Duplicate Transfer Products"
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>
        </div>
        <div className="overflow-x-auto max-h-96">
          <table className="w-full min-w-max" style={{ color: theme.text.primary }}>
            <thead className="border-b sticky top-0 z-10" style={{ backgroundColor: theme.bg.secondary, borderColor: theme.border.default }}>
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>PRODUCT NAME</th>
                <th className="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>BARCODE</th>
                <th className="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>CATEGORY</th>
                <th className="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>BRAND</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>
                  <div className="group relative">
                    PRODUCT QTY
                    <div className="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-2 py-1 text-xs text-white bg-gray-800 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-20">
                      Total quantity from all FIFO batches
                    </div>
                  </div>
                </th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>SRP VALUE</th>
                <th className="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>SUPPLIER</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>CREATED BY</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>TYPE</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>STATUS</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>STOCK STATUS</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>DAYS TO EXPIRY</th>
                <th className="px-3 py-2 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>ACTIONS</th>
              </tr>
            </thead>
            <tbody className="divide-y" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.light }}>
              {inventoryData.length === 0 ? (
                <tr>
                  <td colSpan="13" className="px-3 py-6 text-center">
                    <div className="flex flex-col items-center space-y-3">
                      <Package className="h-12 w-12" style={{ color: theme.text.muted }} />
                      <div style={{ color: theme.text.secondary }}>
                        <p className="text-lg font-medium">No products found</p>
                        <p className="text-sm">Products will appear here when added to warehouse</p>
                      </div>
                    </div>
                  </td>
                </tr>
              ) : (
                inventoryData
                  .filter(product => {
                    // Filter by search term
                    if (searchTerm) {
                      const searchLower = searchTerm.toLowerCase();
                      const matchesSearch = 
                        product.product_name?.toLowerCase().includes(searchLower) ||
                        String(product.barcode || "").toLowerCase().includes(searchLower) ||
                        product.category?.toLowerCase().includes(searchLower) ||
                        product.category_name?.toLowerCase().includes(searchLower) ||
                        product.brand?.toLowerCase().includes(searchLower) ||
                        product.supplier_name?.toLowerCase().includes(searchLower);
                      if (!matchesSearch) return false;
                    }
                    
                    // Filter by category
                    if (filterOptions.category && filterOptions.category !== '') {
                      const productCategory = product.category || product.category_name;
                      if (productCategory !== filterOptions.category) return false;
                    }
                    
                    // Filter by stock status
                    if (filterOptions.stockStatus === 'all') return true;
                    if (filterOptions.stockStatus === 'low') {
                      const qty = product.total_quantity || product.product_quantity || product.quantity || 0;
                      return qty > 0 && qty <= 10;
                    }
                    if (filterOptions.stockStatus === 'out') {
                      const qty = product.total_quantity || product.product_quantity || product.quantity || 0;
                      return qty <= 0;
                    }
                    return true;
                  })
                  .map((product) => {
                    // Check for alert conditions
                    const quantity = product.total_quantity || product.product_quantity || product.quantity || 0;
                    const isLowStock = settings.lowStockAlerts && isStockLow(quantity);
                    const isOutOfStock = quantity <= 0;
                    const isExpiringSoon = product.earliest_expiration && settings.expiryAlerts && isProductExpiringSoon(product.earliest_expiration);
                    const isExpired = product.earliest_expiration && isProductExpired(product.earliest_expiration);
                    
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
                  <tr key={product.product_id} className={rowClass} style={rowStyle}>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        <div className="text-sm font-medium" style={{ color: theme.text.primary }}>
                          {product.product_name}
                        </div>
                       
                      </div>
                    </td>
                    <td className="px-3 py-2 text-sm font-mono" style={{ color: theme.text.primary }}>
                      {product.barcode}
                    </td>
                    <td className="px-3 py-2 text-sm">
                      <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.bg.secondary, color: theme.text.secondary }}>
                        {product.category || product.category_name || 'Uncategorized'}
                      </span>
                    </td>
                    <td className="px-3 py-2 text-sm" style={{ color: theme.text.primary }}>
                      {(() => {
                        if (product.brand_id && brandsData.length > 0) {
                          const brand = brandsData.find(b => b.brand_id == product.brand_id);
                          return brand ? brand.brand : "N/A";
                        }
                        return product.brand || "N/A";
                      })()}
                    </td>
                    <td className="px-3 py-2 text-center">
                      <div className="font-semibold" style={{ 
                        color: (product.total_quantity || product.product_quantity || product.quantity || 0) <= 0 
                          ? theme.colors.danger 
                          : (product.total_quantity || product.product_quantity || product.quantity || 0) <= 10 
                            ? theme.colors.warning 
                            : theme.text.primary
                      }}>
                        {product.total_quantity || product.product_quantity || product.quantity || 0}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-center text-sm" style={{ color: theme.text.primary }}>
                      ₱{(() => {
                        // Try multiple possible SRP field names
                        const srpValue = Number.parseFloat(
                          product.oldest_batch_srp || 
                          product.first_batch_srp || 
                          product.srp || 
                          product.unit_price ||
                          product.transfer_srp ||
                          0
                        );
                        
                        // Debug logging for first product to see available fields
                        if (product.product_name === 'Hot&Spicicy Ketchup') {
                          console.log('🔍 SRP Debug for Hot&Spicicy Ketchup:', {
                            oldest_batch_srp: product.oldest_batch_srp,
                            first_batch_srp: product.first_batch_srp,
                            srp: product.srp,
                            unit_price: product.unit_price,
                            transfer_srp: product.transfer_srp,
                            finalValue: srpValue,
                            allFields: Object.keys(product)
                          });
                        }
                        
                        return srpValue > 0 ? srpValue.toFixed(2) : '0.00';
                      })()}
                    </td>
                    <td className="px-3 py-2 text-sm" style={{ color: theme.text.primary }}>
                      {product.supplier_name || "N/A"}
                    </td>
                    <td className="px-3 py-2 text-center text-sm" style={{ color: theme.text.primary }}>
                      <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.colors.info + '20', color: theme.colors.info }}>
                        {product.batch_entry_by || "System"}
                      </span>
                    </td>
                    <td className="px-3 py-2 text-center">
                      {(() => {
                        const bulk = Number(product.bulk);
                        const prescription = Number(product.prescription);
                        if (bulk && prescription) {
                          return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.colors.warning + '20', color: theme.colors.warning }}>Bulk & Rx</span>;
                        } else if (bulk) {
                          return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.colors.accent + '20', color: theme.colors.accent }}>Bulk</span>;
                        } else if (prescription) {
                          return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.colors.danger + '20', color: theme.colors.danger }}>Rx</span>;
                        } else {
                          return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full" style={{ backgroundColor: theme.bg.secondary, color: theme.text.secondary }}>Regular</span>;
                        }
                      })()}
                    </td>
                    <td className="px-3 py-2 text-center">
                      <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full" style={{
                        backgroundColor: product.status === "Available" ? theme.colors.success + '20' : theme.colors.danger + '20',
                        color: product.status === "Available" ? theme.colors.success : theme.colors.danger
                      }}>
                        {product.status || "Available"}
                      </span>
                    </td>
                    <td className="px-3 py-2 text-center">
                      <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full" style={{
                        backgroundColor: getStockStatus(product.quantity) === 'out of stock' ? theme.colors.danger + '20' : 
                                       getStockStatus(product.quantity) === 'low stock' ? theme.colors.warning + '20' : 
                                       theme.colors.success + '20',
                        color: getStockStatus(product.quantity) === 'out of stock' ? theme.colors.danger : 
                               getStockStatus(product.quantity) === 'low stock' ? theme.colors.warning : 
                               theme.colors.success
                      }}>
                        {getStockStatus(product.quantity)}
                      </span>
                    </td>
                    <td className="px-3 py-2 text-center">
                      {(() => {
                        const daysUntilExpiry = product.days_until_expiry;
                        
                        if (daysUntilExpiry === null || daysUntilExpiry === undefined) {
                          return (
                            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full" style={{
                              backgroundColor: theme.bg.secondary,
                              color: theme.text.muted
                            }}>
                              N/A
                            </span>
                          );
                        } else if (daysUntilExpiry < 0) {
                          return (
                            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full" style={{
                              backgroundColor: theme.colors.danger + '20',
                              color: theme.colors.danger
                            }}>
                              EXPIRED
                            </span>
                          );
                        } else if (daysUntilExpiry <= 30) {
                          return (
                            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full" style={{
                              backgroundColor: theme.colors.warning + '20',
                              color: theme.colors.warning
                            }}>
                              {daysUntilExpiry} days
                            </span>
                          );
                        } else {
                          return (
                            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full" style={{
                              backgroundColor: theme.colors.success + '20',
                              color: theme.colors.success
                            }}>
                              {daysUntilExpiry} days
                            </span>
                          );
                        }
                      })()}
                    </td>
                    <td className="px-3 py-2 text-center">
                      <div className="flex justify-center gap-1">
                        <button onClick={() => openQuantityHistoryModal(product)} className="p-1" style={{ color: theme.colors.success }} title="View Quantity History">
                          <Package className="h-4 w-4" />
                        </button>
                        <button onClick={() => openExpiringBatchModal(product)} className="p-1" style={{ color: theme.colors.info }} title="View Batch Details">
                          <Calendar className="h-4 w-4" />
                        </button>
                        <button onClick={() => openEditProductModal(product)} className="p-1" style={{ color: theme.colors.accent }} title="Edit Product">
                          <Edit className="h-4 w-4" />
                        </button>
                        <button onClick={() => openDeleteModal(product)} className="p-1" style={{ color: theme.colors.danger }} title="Archive Product">
                          <Archive className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                    );
                  })
              )}
            </tbody>
          </table>
          

        </div>
      </div>
    )}
  
            {activeTab === "suppliers" && (
              <div className="rounded-3xl shadow-xl" style={{ backgroundColor: theme.bg.card, boxShadow: `0 25px 50px ${theme.shadow}` }}>
                <div className="px-6 py-4 border-b" style={{ borderColor: theme.border.default }}>
                  <div className="flex justify-between items-center">
                    <h3 className="text-xl font-semibold" style={{ color: theme.text.primary }}>Suppliers</h3>
                    <div className="text-sm" style={{ color: theme.text.secondary }}>
                      {suppliersData.length} suppliers found
                    </div>
                  </div>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full" style={{ color: theme.text.primary }}>
                    <thead className="bg-gray-50 border-b border-gray-200">
                      <tr>
                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>SUPPLIER NAME</th>
                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>CONTACT</th>
                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>EMAIL</th>
                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>CONTACT PERSON</th>
                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>PAYMENT TERMS</th>
                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>LEAD TIME</th>
                        <th className="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider" style={{ color: theme.text.primary }}>ACTIONS</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.light }}>
                      {suppliersData.length === 0 ? (
                        <tr>
                          <td colSpan="7" className="px-6 py-8 text-center">
                            <div className="flex flex-col items-center space-y-3">
                              <User className="h-12 w-12" style={{ color: theme.text.muted }} />
                              <div style={{ color: theme.text.secondary }}>
                                <p className="text-lg font-medium">No suppliers found</p>
                                <p className="text-sm">Suppliers will appear here when added</p>
                              </div>
                            </div>
                          </td>
                        </tr>
                      ) : (
                        suppliersData.map((supplier) => (
                          <tr key={supplier.supplier_id} className="hover:bg-opacity-50" style={{ backgroundColor: 'transparent', hoverBackgroundColor: theme.bg.hover }}>
                            <td className="px-6 py-4 text-sm font-medium" style={{ color: theme.text.primary }}>{supplier.supplier_name}</td>
                            <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>{supplier.supplier_contact}</td>
                            <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>{supplier.supplier_email}</td>
                            <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>{supplier.contact_person || "-"}</td>
                            <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>{supplier.payment_terms || "-"}</td>
                            <td className="px-6 py-4 text-sm" style={{ color: theme.text.primary }}>{supplier.lead_time_days ? `${supplier.lead_time_days} days` : "-"}</td>
                            <td className="px-6 py-4 text-center">
                              <div className="flex justify-center gap-2">
                                <button onClick={() => openEditModal(supplier)} className="p-1" style={{ color: theme.colors.accent }} title="Edit Supplier">
                                  <Edit className="h-4 w-4" />
                                </button>
                                <button onClick={() => openDeleteModal(supplier)} className="p-1" style={{ color: theme.colors.danger }} title="Archive Supplier">
                                  <Archive className="h-4 w-4" />
                                </button>
                              </div>
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        </div>
  
        {/* SUPPLIER MODAL - ALL FIELDS KEPT */}
        {showSupplierModal && (
          <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50">
            <div className="backdrop-blur-md rounded-xl shadow-2xl max-w-5xl w-full mx-4 max-h-[90vh] border" style={{ backgroundColor: theme.bg.card + 'F0', borderColor: theme.border.default }}>
              <div className="px-6 py-4 border-b flex items-center justify-between sticky top-0 z-10" style={{ borderColor: theme.border.default, backgroundColor: theme.bg.card + 'F0' }}>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>Add New Supplier</h3>
                <button onClick={closeSupplierModal} className="hover:opacity-70" style={{ color: theme.text.muted }}>
                  <X className="h-6 w-6" />
                </button>
              </div>
  
              <div className="overflow-y-auto max-h-[calc(90vh-80px)]">
                <form onSubmit={handleAddSupplier} className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Supplier Name *</label>
                    <input
                      type="text"
                      required
                      value={supplierFormData.supplier_name || ""}
                      onChange={(e) => handleSupplierInputChange("supplier_name", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Contact Number *</label>
                    <input
                      type="text"
                      required
                      value={supplierFormData.supplier_contact || ""}
                      onChange={(e) => handleSupplierInputChange("supplier_contact", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Email *</label>
                    <input
                      type="email"
                      required
                      value={supplierFormData.supplier_email || ""}
                      onChange={(e) => handleSupplierInputChange("supplier_email", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Primary Phone</label>
                    <input
                      type="text"
                      value={supplierFormData.primary_phone || ""}
                      onChange={(e) => handleSupplierInputChange("primary_phone", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Primary Email</label>
                    <input
                      type="email"
                      value={supplierFormData.primary_email || ""}
                      onChange={(e) => handleSupplierInputChange("primary_email", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Contact Person</label>
                    <input
                      type="text"
                      value={supplierFormData.contact_person || ""}
                      onChange={(e) => handleSupplierInputChange("contact_person", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Contact Title</label>
                    <input
                      type="text"
                      value={supplierFormData.contact_title || ""}
                      onChange={(e) => handleSupplierInputChange("contact_title", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Payment Terms</label>
                    <input
                      type="text"
                      value={supplierFormData.payment_terms || ""}
                      onChange={(e) => handleSupplierInputChange("payment_terms", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Lead Time (Days)</label>
                    <input
                      type="number"
                      value={supplierFormData.lead_time_days || ""}
                      onChange={(e) => handleSupplierInputChange("lead_time_days", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Order Level</label>
                    <input
                      type="number"
                      value={supplierFormData.order_level || ""}
                      onChange={(e) => handleSupplierInputChange("order_level", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Credit Rating</label>
                    <input
                      type="text"
                      value={supplierFormData.credit_rating || ""}
                      onChange={(e) => handleSupplierInputChange("credit_rating", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div className="md:col-span-3">
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Address</label>
                    <textarea
                      rows={3}
                      value={supplierFormData.supplier_address}
                      onChange={(e) => handleSupplierInputChange("supplier_address", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div className="md:col-span-3">
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Notes</label>
                    <textarea
                      rows={3}
                      value={supplierFormData.notes}
                      onChange={(e) => handleSupplierInputChange("notes", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
                </div>
  
                <div className="flex justify-end space-x-4 mt-6">
                  <button
                    type="button"
                    onClick={closeSupplierModal}
                    className="px-4 py-2 border rounded-md hover:opacity-70"
                    style={{ 
                      borderColor: theme.border.default, 
                      backgroundColor: theme.bg.secondary,
                      color: theme.text.primary
                    }}
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={loading}
                    className="px-4 py-2 rounded-md disabled:opacity-50"
                    style={{ 
                      backgroundColor: theme.colors.accent,
                      color: '#ffffff'
                    }}
                  >
                    {loading ? "Adding..." : "Add Supplier"}
                  </button>
                </div>
              </form>
              </div>
            </div>
          </div>
        )}
  
        {/* Edit Supplier Modal */}
        {showEditModal && (
          <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50">
            <div className="backdrop-blur-md rounded-xl shadow-2xl max-w-5xl w-full mx-4 max-h-[90vh] border" style={{ backgroundColor: theme.bg.card + 'F0', borderColor: theme.border.default }}>
              <div className="px-6 py-4 border-b flex items-center justify-between sticky top-0 z-10" style={{ borderColor: theme.border.default, backgroundColor: theme.bg.card + 'F0' }}>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>Edit Supplier</h3>
                <button onClick={closeEditModal} className="hover:opacity-70" style={{ color: theme.text.muted }}>
                  <X className="h-6 w-6" />
                </button>
              </div>
  
              <div className="overflow-y-auto max-h-[calc(90vh-80px)]">
                <form onSubmit={handleUpdateSupplier} className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Supplier Name *</label>
                    <input
                      type="text"
                      required
                      value={editFormData.supplier_name || ""}
                      onChange={(e) => handleEditInputChange("supplier_name", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Contact Number *</label>
                    <input
                      type="text"
                      required
                      value={editFormData.supplier_contact || ""}
                      onChange={(e) => handleEditInputChange("supplier_contact", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Email *</label>
                    <input
                      type="email"
                      required
                      value={editFormData.supplier_email || ""}
                      onChange={(e) => handleEditInputChange("supplier_email", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Contact Person</label>
                    <input
                      type="text"
                      value={editFormData.contact_person || ""}
                      onChange={(e) => handleEditInputChange("contact_person", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Payment Terms</label>
                    <input
                      type="text"
                      value={editFormData.payment_terms || ""}
                      onChange={(e) => handleEditInputChange("payment_terms", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Lead Time (Days)</label>
                    <input
                      type="number"
                      value={editFormData.lead_time_days || ""}
                      onChange={(e) => handleEditInputChange("lead_time_days", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div className="md:col-span-2">
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Address</label>
                    <textarea
                      rows={3}
                      value={editFormData.supplier_address || ""}
                      onChange={(e) => handleEditInputChange("supplier_address", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div className="md:col-span-2">
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Notes</label>
                    <textarea
                      rows={3}
                      value={editFormData.notes || ""}
                      onChange={(e) => handleEditInputChange("notes", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default, 
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
                </div>
  
                <div className="flex justify-end space-x-4 mt-6">
                  <button
                    type="button"
                    onClick={closeEditModal}
                    className="px-4 py-2 border rounded-md hover:opacity-70"
                    style={{ 
                      borderColor: theme.border.default, 
                      backgroundColor: theme.bg.secondary,
                      color: theme.text.primary
                    }}
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={loading}
                    className="px-4 py-2 rounded-md disabled:opacity-50"
                    style={{ 
                      backgroundColor: theme.colors.accent,
                      color: '#ffffff'
                    }}
                  >
                    {loading ? "Updating..." : "Update Supplier"}
                  </button>
                </div>
              </form>
              </div>
            </div>
          </div>
        )}

        {/* Edit Product Modal */}
        {showEditProductModal && (
          <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50">
            <div className="rounded-xl shadow-2xl max-w-5xl w-full mx-4 max-h-[90vh] border" style={{ 
              backgroundColor: theme.bg.card, 
              borderColor: theme.border.default 
            }}>
              <div className="px-6 py-4 border-b flex items-center justify-between sticky top-0 z-10" style={{ 
                backgroundColor: theme.bg.card, 
                borderColor: theme.border.default 
              }}>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>Edit Product</h3>
                <button 
                  onClick={closeEditProductModal} 
                  className="transition-colors"
                  style={{ color: theme.text.muted }}
                  onMouseEnter={(e) => e.target.style.color = theme.text.primary}
                  onMouseLeave={(e) => e.target.style.color = theme.text.muted}
                >
                  <X className="h-6 w-6" />
                </button>
              </div>
  
              <div className="overflow-y-auto max-h-[calc(90vh-80px)]">
                <form onSubmit={handleUpdateProduct} className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Product Name *</label>
                    <input
                      type="text"
                      required
                      value={editProductFormData.product_name || ""}
                      onChange={(e) => handleEditProductInputChange("product_name", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>
  
                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Barcode</label>
                    <input
                      type="text"
                      value={editProductFormData.barcode || ""}
                      onChange={(e) => handleEditProductInputChange("barcode", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Category *</label>
                    <select
                      required
                      value={editProductFormData.category_id || ""}
                      onChange={(e) => handleEditProductInputChange("category_id", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    >
                      <option value="">Select Category</option>
                      {categoriesData.map((category) => (
                        <option key={category.category_id} value={category.category_id}>
                          {category.category_name}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Suggested Retail Price (SRP)</label>
                    <input
                      type="number"
                      step="0.01"
                      value={editProductFormData.srp || ""}
                      onChange={(e) => handleEditProductInputChange("srp", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Quantity *</label>
                    <input
                      type="number"
                      required
                      value={editProductFormData.quantity || ""}
                      onChange={(e) => handleEditProductInputChange("quantity", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Brand</label>
                    <select
                      value={editProductFormData.brand_id || ""}
                      onChange={(e) => handleEditProductInputChange("brand_id", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    >
                      <option value="">Select Brand</option>
                      {brandsData.map((brand) => (
                        <option key={brand.brand_id} value={brand.brand_id}>
                          {brand.brand}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Supplier</label>
                    <select
                      value={editProductFormData.supplier_id || ""}
                      onChange={(e) => handleEditProductInputChange("supplier_id", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    >
                      <option value="">Select Supplier</option>
                      {suppliersData.map((supplier) => (
                        <option key={supplier.supplier_id} value={supplier.supplier_id}>
                          {supplier.supplier_name}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Expiration Date</label>
                    <input
                      type="date"
                      value={editProductFormData.expiration || ""}
                      onChange={(e) => {
                        const selectedDate = e.target.value;
                        // Validate that the date is not in the past
                        if (selectedDate && new Date(selectedDate) < new Date()) {
                          safeToast("warning", "Expiration date cannot be in the past. Please select a future date.");
                          return;
                        }
                        handleEditProductInputChange("expiration", selectedDate);
                      }}
                      min={new Date().toISOString().split('T')[0]}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                    <p className="text-xs mt-1" style={{ color: theme.text.muted }}>
                      Required for proper FIFO inventory tracking
                    </p>
                    {editProductFormData.expiration && (
                      <p className="text-xs mt-1" style={{ color: theme.colors.accent }}>
                        Expires: {new Date(editProductFormData.expiration).toLocaleDateString()}
                      </p>
                    )}
                  </div>

                  <div className="md:col-span-2">
                    <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Description</label>
                    <textarea
                      rows={3}
                      value={editProductFormData.description || ""}
                      onChange={(e) => handleEditProductInputChange("description", e.target.value)}
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                  </div>

                  <div className="md:col-span-2">
                    <div className="flex items-center space-x-6">
                      <div className="flex items-center space-x-2">
                        <input
                          type="checkbox"
                          id="editPrescription"
                          checked={editProductFormData.prescription === 1}
                          onChange={(e) => handleEditProductInputChange("prescription", e.target.checked ? 1 : 0)}
                          className="h-4 w-4 rounded focus:ring-2"
                          style={{ 
                            accentColor: theme.colors.accent,
                            borderColor: theme.border.default
                          }}
                        />
                        <label htmlFor="editPrescription" className="text-sm font-medium" style={{ color: theme.text.secondary }}>
                          Prescription Required
                        </label>
                      </div>
                      <div className="flex items-center space-x-2">
                        <input
                          type="checkbox"
                          id="editBulk"
                          checked={editProductFormData.bulk === 1}
                          onChange={(e) => handleEditProductInputChange("bulk", e.target.checked ? 1 : 0)}
                          className="h-4 w-4 rounded focus:ring-2"
                          style={{ 
                            accentColor: theme.colors.accent,
                            borderColor: theme.border.default
                          }}
                        />
                        <label htmlFor="editBulk" className="text-sm font-medium" style={{ color: theme.text.secondary }}>
                          Bulk Product
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
  
                <div className="flex justify-end space-x-4 mt-6">
                  <button
                    type="button"
                    onClick={closeEditProductModal}
                    className="px-4 py-2 border rounded-md transition-colors"
                    style={{ 
                      borderColor: theme.border.default,
                      backgroundColor: theme.bg.secondary,
                      color: theme.text.primary
                    }}
                    onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.hover}
                    onMouseLeave={(e) => e.target.style.backgroundColor = theme.bg.secondary}
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={loading}
                    className="px-4 py-2 rounded-md transition-colors disabled:opacity-50"
                    style={{ 
                      backgroundColor: theme.colors.accent,
                      color: theme.text.primary
                    }}
                    onMouseEnter={(e) => !loading && (e.target.style.backgroundColor = theme.colors.accent + 'dd')}
                    onMouseLeave={(e) => !loading && (e.target.style.backgroundColor = theme.colors.accent)}
                  >
                    {loading ? "Updating..." : "Update Product"}
                  </button>
                </div>
              </form>
              </div>
            </div>
          </div>
        )}
  
  
  {/* Delete Confirmation Modal */}
  {showDeleteModal && (
    <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50">
      <div className="bg-white/95 backdrop-blur-md rounded-xl shadow-2xl p-6 border border-gray-200/50 w-96">
        <h3 className="text-lg font-semibold text-gray-900 mb-4">Confirm Archive</h3>
        <p className="text-gray-700 mb-4">Are you sure you want to archive this item?</p>
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
              {loading ? "Archiving..." : "Archive"}
            </button>
        </div>
      </div>
    </div>
  )}
  

  {showUpdateStockModal && existingProduct && (
    <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-50">
      <div 
        className="backdrop-blur-md rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[95vh] border"
        style={{
          backgroundColor: theme.bg.card,
          borderColor: theme.border.default,
          color: theme.text.primary
        }}
      >
        <div 
          className="px-6 py-4 border-b flex items-center justify-between sticky top-0 backdrop-blur-md z-10"
          style={{
            backgroundColor: theme.bg.card,
            borderColor: theme.border.default
          }}
        >
          <h3 
            className="text-lg font-semibold"
            style={{ color: theme.text.primary }}
          >
            Update Product Stock
          </h3>
          <button 
            onClick={closeUpdateStockModal} 
            style={{ color: theme.text.muted }}
            className="hover:opacity-70 transition-opacity"
          >
            <X className="h-6 w-6" />
          </button>
        </div>

        <div className="overflow-y-auto max-h-[calc(95vh-80px)]">
          <div className="p-6">
            
            {/* Product Details Section */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
              <div>
                <label 
                  className="block text-sm font-medium mb-1"
                  style={{ color: theme.text.secondary }}
                >
                  Product Name
                </label>
                <input
                  type="text"
                  value={existingProduct.product_name || ""}
                  readOnly
                  className="w-full px-3 py-2 border rounded-md"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.primary
                  }}
                />
              </div>
              <div>
                <label 
                  className="block text-sm font-medium mb-1"
                  style={{ color: theme.text.secondary }}
                >
                  Barcode
                </label>
                <input
                  type="text"
                  value={existingProduct.barcode || ""}
                  readOnly
                  className="w-full px-3 py-2 border rounded-md"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.primary
                  }}
                />
              </div>
              <div>
                <label 
                  className="block text-sm font-medium mb-1"
                  style={{ color: theme.text.secondary }}
                >
                  Category
                </label>
                <input
                  type="text"
                  value={(() => {
                    // Try direct category name first
                    if (existingProduct.category_name || existingProduct.category) {
                      return existingProduct.category_name || existingProduct.category;
                    }
                    // Try to resolve from category_id
                    if (existingProduct.category_id && categoriesData.length > 0) {
                      const category = categoriesData.find(cat => cat.category_id == existingProduct.category_id);
                      return category ? category.category_name : `Category ID: ${existingProduct.category_id}`;
                    }
                    return "N/A";
                  })()}
                  readOnly
                  className="w-full px-3 py-2 border rounded-md"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.primary
                  }}
                />
              </div>
              <div>
                <label 
                  className="block text-sm font-medium mb-1"
                  style={{ color: theme.text.secondary }}
                >
                  Brand
                </label>
                <input
                  type="text"
                  value={(() => {
                    // Try direct brand name first
                    if (existingProduct.brand) {
                      return existingProduct.brand;
                    }
                    // Try to resolve from brand_id
                    if (existingProduct.brand_id && brandsData.length > 0) {
                      const brand = brandsData.find(b => b.brand_id == existingProduct.brand_id);
                      return brand ? brand.brand : `Brand ID: ${existingProduct.brand_id}`;
                    }
                    return "N/A";
                  })()}
                  readOnly
                  className="w-full px-3 py-2 border rounded-md"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.primary
                  }}
                />
              </div>
              <div>
                <label 
                  className="block text-sm font-medium mb-1"
                  style={{ color: theme.text.secondary }}
                >
                  Current Stock
                </label>
                <input
                  type="text"
                  value={existingProduct.quantity || "0"}
                  readOnly
                  className="w-full px-3 py-2 border rounded-md"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.primary
                  }}
                />
              </div>
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label 
                    className="block text-sm font-medium"
                    style={{ color: theme.text.secondary }}
                  >
                    SRP *
                    <span 
                      className="ml-2 text-xs font-normal px-2 py-1 rounded" 
                      style={{ 
                        backgroundColor: theme.colors.danger + '20', 
                        color: theme.colors.danger 
                      }}
                    >
                      REQUIRED FOR FIFO
                    </span>
                  </label>
                  <div className="flex items-center">
                    <input
                      type="checkbox"
                      id="editSrp"
                      checked={editSrpEnabled}
                      onChange={(e) => {
                        setEditSrpEnabled(e.target.checked);
                        if (e.target.checked) {
                          setNewSrp(existingProduct.srp || existingProduct.unit_price || "");
                        } else {
                          setNewSrp("");
                        }
                      }}
                      className="h-4 w-4 rounded"
                      style={{
                        accentColor: theme.colors.accent,
                        borderColor: theme.border.default
                      }}
                    />
                    <label 
                      htmlFor="editSrp" 
                      className="ml-2 text-sm"
                      style={{ color: theme.text.muted }}
                    >
                      Edit SRP for this batch
                    </label>
                  </div>
                </div>
                {editSrpEnabled ? (
                  <div>
                    <input
                      type="number"
                      step="0.01"
                      required
                      value={newSrp || ""}
                      onChange={(e) => setNewSrp(e.target.value)}
                      placeholder="Enter SRP for this batch"
                      className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                      style={{ 
                        borderColor: (newSrp && parseFloat(newSrp) > 0) ? theme.border.default : theme.colors.danger,
                        backgroundColor: theme.bg.secondary,
                        color: theme.text.primary,
                        focusRingColor: theme.colors.accent
                      }}
                    />
                    <p className="text-xs mt-1" style={{ color: theme.text.muted }}>
                      ⚠️ Enter the SRP for this specific FIFO batch
                    </p>
                  </div>
                ) : (
                  <div>
                    <input
                      type="text"
                      readOnly
                      className="w-full px-3 py-2 border rounded-md text-sm"
                      placeholder="SRP"
                      value={`₱${Number.parseFloat(existingProduct.srp || 0).toFixed(2)}`}
                      style={{ 
                        borderColor: theme.border.default,
                        backgroundColor: theme.bg.hover,
                        color: theme.text.primary
                      }}
                    />
                    <p className="text-xs mt-1" style={{ color: theme.text.muted }}>
                      ⚠️ Using existing product SRP. Check &ldquo;Edit SRP&rdquo; to set a different price for this batch.
                    </p>
                  </div>
                )}
              </div>
            </div>

            {/* Description Section */}
            <div className="mb-6">
              <label 
                className="block text-sm font-medium mb-1"
                style={{ color: theme.text.secondary }}
              >
                Description
              </label>
              <textarea
                value={existingProduct.description || ""}
                readOnly
                rows={3}
                className="w-full px-3 py-2 border rounded-md"
                style={{
                  backgroundColor: theme.bg.hover,
                  borderColor: theme.border.default,
                  color: theme.text.primary
                }}
              />
            </div>

            {/* Product Type Override Section */}
            <div 
              className="mb-6 p-4 rounded-lg border"
              style={{
                backgroundColor: theme.colors.info + '10',
                borderColor: theme.colors.info + '30'
              }}
            >
              <h4 
                className="text-sm font-semibold mb-3 flex items-center"
                style={{ color: theme.colors.info }}
              >
                🔧 Product Type Detection
              </h4>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label 
                    className="block text-sm font-medium mb-1"
                    style={{ color: theme.text.secondary }}
                  >
                    Detected Type: <span 
                      className="font-semibold"
                      style={{ 
                        color: existingProduct.product_type === "Medicine" 
                          ? theme.colors.success 
                          : theme.colors.warning 
                      }}
                    >
                      {existingProduct.product_type}
                    </span>
                  </label>
                  <p 
                    className="text-xs"
                    style={{ color: theme.text.muted }}
                  >
                    Auto-detected based on category and product name
                  </p>
                </div>
                <div>
                  <label 
                    className="block text-sm font-medium mb-1"
                    style={{ color: theme.text.secondary }}
                  >
                    Override Product Type (if needed)
                  </label>
                  <select
                    value={existingProduct.product_type || "Non-Medicine"}
                    onChange={(e) => {
                      const newProductType = e.target.value;
                      setExistingProduct(prev => ({
                        ...prev,
                        product_type: newProductType
                      }));
                      console.log("🔧 Product type manually overridden to:", newProductType);
                    }}
                    className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                    style={{
                      backgroundColor: theme.bg.card,
                      borderColor: theme.border.default,
                      color: theme.text.primary,
                      focusRingColor: theme.colors.accent
                    }}
                  >
                    <option value="Non-Medicine">Non-Medicine</option>
                    <option value="Medicine">Medicine</option>
                  </select>
                  <p 
                    className="text-xs mt-1"
                    style={{ color: theme.text.muted }}
                  >
                    Change this if the auto-detection is incorrect
                  </p>
                </div>
              </div>
            </div>

            {/* Bulk Configuration Section */}
            <div 
              className="mb-6 p-4 rounded-lg border"
              style={{
                backgroundColor: theme.bg.hover,
                borderColor: theme.border.default
              }}
            >
              <h4 
                className="text-sm font-semibold mb-4 flex items-center"
                style={{ color: theme.text.primary }}
              >
                ⚙️ Configuration Mode
              </h4>
              <p 
                className="text-xs mb-4"
                style={{ color: theme.text.muted }}
              >
                Choose how you want to configure the product quantity. <strong>Pieces Mode</strong> is recommended for quick stock updates.
              </p>
              
              {/* Status Indicator */}
              <div 
                className="mb-4 p-2 rounded-lg border" 
                style={{
                  backgroundColor: (() => {
                    if (stockUpdateConfigMode === "pieces") {
                      return (newStockQuantity && newStockQuantity > 0) 
                        ? theme.colors.success + '20'
                        : theme.colors.warning + '20';
                    } else {
                      // Bulk mode validation
                      if (existingProduct.product_type === "Medicine") {
                        return (newStockBoxes && newStockBoxes > 0 && newStockStripsPerBox && newStockStripsPerBox > 0 && newStockTabletsPerStrip && newStockTabletsPerStrip > 0)
                          ? theme.colors.success + '20'
                          : theme.colors.warning + '20';
                      } else if (existingProduct.product_type === "Non-Medicine") {
                        return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                          ? theme.colors.success + '20'
                          : theme.colors.warning + '20';
                      } else {
                        // Unknown product type - check if bulk fields are filled
                        return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                          ? theme.colors.success + '20'
                          : theme.colors.warning + '20';
                      }
                    }
                  })(),
                  borderColor: (() => {
                    if (stockUpdateConfigMode === "pieces") {
                      return (newStockQuantity && newStockQuantity > 0) 
                        ? theme.colors.success + '40'
                        : theme.colors.warning + '40';
                    } else {
                      // Bulk mode validation
                      if (existingProduct.product_type === "Medicine") {
                        return (newStockBoxes && newStockBoxes > 0 && newStockStripsPerBox && newStockStripsPerBox > 0 && newStockTabletsPerStrip && newStockTabletsPerStrip > 0)
                          ? theme.colors.success + '40'
                          : theme.colors.warning + '40';
                      } else if (existingProduct.product_type === "Non-Medicine") {
                        return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                          ? theme.colors.success + '40'
                          : theme.colors.warning + '40';
                      } else {
                        // Unknown product type - check if bulk fields are filled
                        return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                          ? theme.colors.success + '40'
                          : theme.colors.warning + '40';
                      }
                    }
                  })()
                }}
              >
                <p 
                  className="text-xs"
                  style={{
                    color: (() => {
                      if (stockUpdateConfigMode === "pieces") {
                        return (newStockQuantity && newStockQuantity > 0) 
                          ? theme.colors.success
                          : theme.colors.warning;
                      } else {
                        // Bulk mode validation
                        if (existingProduct.product_type === "Medicine") {
                          return (newStockBoxes && newStockBoxes > 0 && newStockStripsPerBox && newStockStripsPerBox > 0 && newStockTabletsPerStrip && newStockTabletsPerStrip > 0)
                            ? theme.colors.success
                            : theme.colors.warning;
                        } else if (existingProduct.product_type === "Non-Medicine") {
                          return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                            ? theme.colors.success
                            : theme.colors.warning;
                        } else {
                          // Unknown product type - check if bulk fields are filled
                          return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                            ? theme.colors.success
                            : theme.colors.warning;
                        }
                      }
                    })()
                  }}
                >
                  <strong>Status:</strong> {
                    stockUpdateConfigMode === "pieces" 
                      ? (newStockQuantity && newStockQuantity > 0) 
                        ? "✅ Ready to update stock" 
                        : "⏳ Enter quantity to enable button"
                      : (() => {
                          // Bulk mode status
                          if (existingProduct.product_type === "Medicine") {
                            return (newStockBoxes && newStockBoxes > 0 && newStockStripsPerBox && newStockStripsPerBox > 0 && newStockTabletsPerStrip && newStockTabletsPerStrip > 0)
                              ? "✅ Ready to update stock (Medicine Bulk Mode)"
                              : "⏳ Fill Medicine fields: Boxes, Strips per Box, Tablets per Strip";
                          } else if (existingProduct.product_type === "Non-Medicine") {
                            return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                              ? "✅ Ready to update stock (Non-Medicine Bulk Mode)"
                              : "⏳ Fill Non-Medicine fields: Boxes, Pieces per Box";
                          } else {
                            // Unknown product type
                            return (newStockBoxes && newStockBoxes > 0 && newStockPiecesPerPack && newStockPiecesPerPack > 0)
                              ? "✅ Ready to update stock (Bulk Mode)"
                              : "⏳ Fill bulk fields: Boxes, Pieces per Box";
                          }
                        })()
                  }
                </p>
              </div>
              
              <div className="flex items-center space-x-6 mb-4">
                <div 
                  className="flex items-center space-x-2 p-2 rounded-lg transition-colors"
                  style={{
                    backgroundColor: stockUpdateConfigMode === "bulk" ? theme.bg.hover : 'transparent',
                    borderColor: stockUpdateConfigMode === "bulk" ? theme.border.default : 'transparent',
                    borderWidth: stockUpdateConfigMode === "bulk" ? '1px' : '0px'
                  }}
                >
                  <input
                    type="radio"
                    id="bulkMode"
                    name="configMode"
                    value="bulk"
                    checked={stockUpdateConfigMode === "bulk"}
                    onChange={(e) => setStockUpdateConfigMode("bulk")}
                    className="h-4 w-4"
                    style={{
                      accentColor: theme.colors.accent,
                      borderColor: theme.border.default
                    }}
                  />
                  <label 
                    htmlFor="bulkMode" 
                    className="text-sm font-medium cursor-pointer"
                    style={{ color: theme.text.secondary }}
                  >
                    📦 Bulk Mode (Boxes × Pieces)
                  </label>
                </div>
                <div 
                  className="flex items-center space-x-2 p-2 rounded-lg transition-colors"
                  style={{
                    backgroundColor: stockUpdateConfigMode === "pieces" ? theme.bg.hover : 'transparent',
                    borderColor: stockUpdateConfigMode === "pieces" ? theme.border.default : 'transparent',
                    borderWidth: stockUpdateConfigMode === "pieces" ? '1px' : '0px'
                  }}
                >
                  <input
                    type="radio"
                    id="piecesMode"
                    name="configMode"
                    value="pieces"
                    checked={stockUpdateConfigMode === "pieces"}
                    onChange={(e) => setStockUpdateConfigMode("pieces")}
                    className="h-4 w-4"
                    style={{
                      accentColor: theme.colors.accent,
                      borderColor: theme.border.default
                    }}
                  />
                  <label 
                    htmlFor="piecesMode" 
                    className="text-sm font-medium cursor-pointer"
                    style={{ color: theme.text.secondary }}
                  >
                    🔢 Pieces Mode (Direct Total) - <span 
                      className="font-semibold"
                      style={{ color: theme.colors.success }}
                    >
                      Recommended
                    </span>
                  </label>
                </div>
              </div>

              {/* Show input fields based on configuration mode */}
              {(() => {
                if (existingProduct.product_type === "Medicine") {
                  // Medicine Configuration
                  if (stockUpdateConfigMode === "bulk") {
                    // Medicine with Bulk Mode (Boxes × Strips × Tablets)
                    return (
                      <div 
                        className="p-4 rounded-lg border"
                        style={{
                          backgroundColor: theme.colors.success + '10',
                          borderColor: theme.colors.success + '30'
                        }}
                      >
                        <h4 
                          className="text-sm font-semibold mb-3 flex items-center"
                          style={{ color: theme.colors.success }}
                        >
                          💊 Medicine Configuration (Bulk Mode)
                        </h4>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                          <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Number of Boxes *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockBoxes || ""}
                              onChange={(e) => setNewStockBoxes(e.target.value)}
                              placeholder="Enter boxes"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                          <div>
                            <label className="text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Strips per Box *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockStripsPerBox || ""}
                              onChange={(e) => setNewStockStripsPerBox(e.target.value)}
                              placeholder="Enter strips per box"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                          <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Tablets per Strip *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockTabletsPerStrip || ""}
                              onChange={(e) => setNewStockTabletsPerStrip(e.target.value)}
                              placeholder="Enter tablets per strip"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                        </div>
                        
                        {/* Auto-computed Total Display */}
                        <div 
                          className="mt-4 p-3 rounded border"
                          style={{
                            backgroundColor: theme.bg.card,
                            borderColor: theme.colors.success + '30'
                          }}
                        >
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.success }}
                          >
                            <span className="font-medium">Auto-computed Total: </span>
                            <span 
                              className="text-lg font-bold"
                              style={{ color: theme.colors.success }}
                            >
                              {parseInt(newStockBoxes || 0) * parseInt(newStockStripsPerBox || 0) * parseInt(newStockTabletsPerStrip || 0)} tablets
                            </span>
                          </div>
                          <div 
                            className="text-xs mt-1"
                            style={{ color: theme.colors.success }}
                          >
                            Formula: {newStockBoxes || 0} boxes × {newStockStripsPerBox || 0} strips × {newStockTabletsPerStrip || 0} tablets
                          </div>
                        </div>
                      </div>
                    );
                  } else {
                    // Medicine with Pieces Mode (Direct Total Input)
                    return (
                      <div 
                        className="p-4 rounded-lg border"
                        style={{
                          backgroundColor: theme.bg.hover,
                          borderColor: theme.border.default
                        }}
                      >
                        <h4 
                          className="text-sm font-semibold mb-3 flex items-center"
                          style={{ color: theme.text.primary }}
                        >
                          💊 Medicine Configuration (Pieces Mode)
                        </h4>
                                                 <div>
                           <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Total Tablets to Add *</label>
                           <input
                             type="number"
                             min="1"
                             value={newStockQuantity || ""}
                             onChange={(e) => setNewStockQuantity(e.target.value)}
                             placeholder="Enter total number of tablets"
                             className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                             style={{ 
                               borderColor: theme.border.default,
                               backgroundColor: theme.bg.secondary,
                               color: theme.text.primary,
                               focusRingColor: theme.colors.accent
                             }}
                           />
                           <p className="text-xs mt-1" style={{ color: theme.text.muted }}>Direct input of total tablets for this medicine. <strong>Tip:</strong> This is the fastest way to add stock!</p>
                         </div>
                      </div>
                    );
                  }
                } else if (existingProduct.product_type === "Non-Medicine") {
                  // Non-Medicine Configuration
                  if (stockUpdateConfigMode === "bulk") {
                    // Non-Medicine with Bulk Mode (Boxes × Pieces per Box)
                    return (
                      <div 
                        className="p-4 rounded-lg border"
                        style={{
                          backgroundColor: theme.colors.warning + '10',
                          borderColor: theme.colors.warning + '30'
                        }}
                      >
                        <h4 
                          className="text-sm font-semibold mb-3 flex items-center"
                          style={{ color: theme.colors.warning }}
                        >
                          📦 Non-Medicine Configuration (Bulk Mode)
                        </h4>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                          <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Number of Boxes *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockBoxes || ""}
                              onChange={(e) => setNewStockBoxes(e.target.value)}
                              placeholder="Enter boxes"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                          <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Pieces per Box *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockPiecesPerPack || ""}
                              onChange={(e) => setNewStockPiecesPerPack(e.target.value)}
                              placeholder="Enter pieces per box"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                        </div>
                        
                        {/* Auto-computed Total Display */}
                        <div 
                          className="mt-4 p-3 rounded border"
                          style={{
                            backgroundColor: theme.bg.card,
                            borderColor: theme.colors.warning + '30'
                          }}
                        >
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.warning }}
                          >
                            <span className="font-medium">Auto-computed Total: </span>
                            <span 
                              className="text-lg font-bold"
                              style={{ color: theme.colors.warning }}
                            >
                              {parseInt(newStockBoxes || 0) * parseInt(newStockPiecesPerPack || 0)} pieces
                            </span>
                          </div>
                          <div 
                            className="text-xs mt-1"
                            style={{ color: theme.colors.warning }}
                          >
                            Formula: {newStockBoxes || 0} boxes × {newStockPiecesPerPack || 0} pieces
                          </div>
                        </div>
                      </div>
                    );
                  } else {
                    // Non-Medicine with Pieces Mode (Direct Total Input)
                    return (
                      <div 
                        className="p-4 rounded-lg border"
                        style={{
                          backgroundColor: theme.bg.hover,
                          borderColor: theme.border.default
                        }}
                      >
                        <h4 
                          className="text-sm font-semibold mb-3 flex items-center"
                          style={{ color: theme.text.primary }}
                        >
                          📦 Non-Medicine Configuration (Pieces Mode)
                        </h4>
                        <div>
                          <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Total Pieces to Add *</label>
                          <input
                            type="number"
                            min="1"
                            value={newStockQuantity || ""}
                            onChange={(e) => setNewStockQuantity(e.target.value)}
                            placeholder="Enter total number of pieces"
                            className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                            style={{ 
                              borderColor: theme.border.default,
                              backgroundColor: theme.bg.secondary,
                              color: theme.text.primary,
                              focusRingColor: theme.colors.accent
                            }}
                          />
                          <p className="text-xs mt-1" style={{ color: theme.text.muted }}>Direct input of total pieces for this product. <strong>Tip:</strong> This is the fastest way to add stock!</p>
                        </div>
                      </div>
                    );
                  }
                } else {
                  // Default Configuration (Unknown Product Type)
                  if (stockUpdateConfigMode === "bulk") {
                    return (
                      <div 
                        className="p-4 rounded-lg border"
                        style={{
                          backgroundColor: theme.bg.hover,
                          borderColor: theme.border.default
                        }}
                      >
                        <h4 
                          className="text-sm font-semibold mb-3 flex items-center"
                          style={{ color: theme.text.primary }}
                        >
                          📦 Product Configuration (Bulk Mode)
                        </h4>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                          <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Number of Boxes *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockBoxes || ""}
                              onChange={(e) => setNewStockBoxes(e.target.value)}
                              placeholder="Enter boxes"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                          <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Pieces per Box *</label>
                            <input
                              type="number"
                              min="1"
                              value={newStockPiecesPerPack || ""}
                              onChange={(e) => setNewStockPiecesPerPack(e.target.value)}
                              placeholder="Enter pieces per box"
                              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                              style={{ 
                                borderColor: theme.border.default,
                                backgroundColor: theme.bg.secondary,
                                color: theme.text.primary,
                                focusRingColor: theme.colors.accent
                              }}
                            />
                          </div>
                        </div>
                        
                        {/* Auto-computed Total Display */}
                        <div 
                          className="mt-4 p-3 rounded border"
                          style={{
                            backgroundColor: theme.bg.card,
                            borderColor: theme.border.default
                          }}
                        >
                          <div 
                            className="text-sm"
                            style={{ color: theme.text.secondary }}
                          >
                            <span className="font-medium">Auto-computed Total: </span>
                            <span 
                              className="text-lg font-bold"
                              style={{ color: theme.text.primary }}
                            >
                              {parseInt(newStockBoxes || 0) * parseInt(newStockPiecesPerPack || 0)} pieces
                            </span>
                          </div>
                          <div 
                            className="text-xs mt-1"
                            style={{ color: theme.text.muted }}
                          >
                            Formula: {newStockBoxes || 0} boxes × {newStockPiecesPerPack || 0} pieces
                          </div>
                        </div>
                      </div>
                    );
                  } else {
                    return (
                      <div 
                        className="p-4 rounded-lg border"
                        style={{
                          backgroundColor: theme.bg.hover,
                          borderColor: theme.border.default
                        }}
                      >
                        <h4 
                          className="text-sm font-semibold mb-3 flex items-center"
                          style={{ color: theme.text.primary }}
                        >
                          📦 Product Configuration (Pieces Mode)
                        </h4>
                        <div>
                          <label 
                            className="block text-sm font-medium mb-1"
                            style={{ color: theme.text.secondary }}
                          >
                            Total Pieces to Add *
                          </label>
                          <input
                            type="number"
                            min="1"
                            value={newStockQuantity || ""}
                            onChange={(e) => setNewStockQuantity(e.target.value)}
                            placeholder="Enter total number of pieces"
                            className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                            style={{
                              backgroundColor: theme.bg.card,
                              borderColor: theme.border.default,
                              color: theme.text.primary,
                              focusRingColor: theme.colors.accent
                            }}
                          />
                          <p 
                            className="text-xs mt-1"
                            style={{ color: theme.text.muted }}
                          >
                            Direct input of total pieces for this product
                          </p>
                        </div>
                      </div>
                    );
                  }
                }
              })()}
            </div>

            {/* Expiration Date Section */}
            <div className="mb-6">
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>
                Expiration Date *
                <span className="ml-2 text-xs font-normal px-2 py-1 rounded" style={{ backgroundColor: theme.colors.danger + '20', color: theme.colors.danger }}>
                  REQUIRED FOR FIFO
                </span>
              </label>
              <input
                type="date"
                value={newStockExpiration || ""}
                onChange={(e) => {
                  const selectedDate = e.target.value;
                  // Validate that the date is not in the past
                  if (selectedDate && new Date(selectedDate) < new Date()) {
                    safeToast("warning", "Expiration date cannot be in the past. Please select a future date.");
                    return;
                  }
                  setNewStockExpiration(selectedDate);
                }}
                min={new Date().toISOString().split('T')[0]}
                required
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: newStockExpiration ? theme.border.default : theme.colors.danger,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
              <p className="text-xs mt-1" style={{ color: theme.text.muted }}>
                ⚠️ <strong>Required:</strong> Expiration date is mandatory for FIFO inventory tracking. Each batch must have an expiration date.
              </p>
              {newStockExpiration && (
                <p className="text-xs mt-1" style={{ color: theme.colors.accent }}>
                  ✓ Expires: {new Date(newStockExpiration).toLocaleDateString()}
                </p>
              )}
            </div>

            {/* Action Buttons */}
            <div className="flex justify-between items-center mt-6">
              <div>
                {temporaryProducts.length > 0 && (
                  <button
                    type="button"
                    onClick={openBatchEntryModal}
                    className="px-4 py-2 text-white rounded-md flex items-center hover:opacity-80 transition-opacity"
                    style={{ backgroundColor: theme.colors.warning }}
                  >
                    <Package className="h-4 w-4 mr-2" />
                    View Batch ({temporaryProducts.length})
                  </button>
                )}
              </div>
              <div className="flex space-x-4">
                <button
                  type="button"
                  onClick={closeUpdateStockModal}
                  className="px-4 py-2 border rounded-md hover:opacity-70 transition-opacity"
                  style={{
                    borderColor: theme.border.default,
                    backgroundColor: theme.bg.hover,
                    color: theme.text.secondary
                  }}
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleAddStockToBatch}
                  disabled={loading || (() => {
                    if (stockUpdateConfigMode === "bulk") {
                      if (existingProduct.product_type === "Medicine") {
                        // Medicine bulk mode: validate boxes, strips per box, tablets per strip
                        return (!newStockBoxes || newStockBoxes <= 0 || !newStockStripsPerBox || newStockStripsPerBox <= 0 || !newStockTabletsPerStrip || newStockTabletsPerStrip <= 0);
                      } else {
                        // Non-Medicine bulk mode: validate boxes, pieces per box
                        return (!newStockBoxes || newStockBoxes <= 0 || !newStockPiecesPerPack || newStockPiecesPerPack <= 0);
                      }
                    } else {
                      // Pieces mode: validate direct input
                      return (!newStockQuantity || newStockQuantity <= 0);
                    }
                  })()}
                  className="px-4 py-2 text-white rounded-md disabled:opacity-50 hover:opacity-80 transition-opacity"
                  style={{ backgroundColor: theme.colors.success }}
                >
                  {loading ? "Adding..." : "Add to Batch"}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )}
            {showNewProductModal && (
        <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-[9999] p-4">
          <div 
            className="backdrop-blur-md rounded-xl shadow-2xl max-w-6xl w-full max-h-[95vh] overflow-hidden border"
            style={{
              backgroundColor: theme.bg.card,
              borderColor: theme.border.default
            }}
          >
        <div 
          className="px-6 py-4 border-b flex items-center justify-between sticky top-0 backdrop-blur-md z-10"
          style={{
            backgroundColor: theme.bg.card,
            borderColor: theme.border.default
          }}
        >
          <h3 
            className="text-lg font-semibold"
            style={{ color: theme.text.primary }}
          >
            Add New Product
          </h3>
          <button 
            onClick={closeNewProductModal} 
            style={{ color: theme.text.muted }}
            className="hover:opacity-70 transition-opacity"
          >
            <X className="h-6 w-6" />
          </button>
        </div>

        <div className="overflow-y-auto max-h-[calc(95vh-80px)]">
          <form onSubmit={handleAddNewProduct} className="p-6">
            
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Product Name *</label>
              <input
                key="product_name_input"
                type="text"
                required
                value={newProductForm.product_name || ""}
                onChange={(e) => handleNewProductInputChange("product_name", e.target.value)}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Barcode</label>
              <input
                key="barcode_input"
                type="text"
                value={newProductForm.barcode || ""}
                onChange={(e) => handleNewProductInputChange("barcode", e.target.value)}
                placeholder="Scan or enter barcode"
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Category *</label>
              <select
                required
                value={newProductForm.category_id || ""}
                onChange={(e) => {
                  handleNewProductInputChange("category_id", e.target.value);
                }}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              >
                <option value="">Select Category</option>
                {categoriesData.map((category) => (
                  <option key={category.category_id} value={category.category_id}>
                    {category.category_name}
                  </option>
                ))}
              </select>
              <p 
                className="text-xs mt-1"
                style={{ color: theme.text.muted }}
              >
                Available categories: {categoriesData.length}
              </p>
            </div>
            <div>
              <label 
                className="block text-sm font-medium mb-1"
                style={{ color: theme.text.secondary }}
              >
                Product Type *
              </label>
              <div className="flex items-center space-x-3">
                <select
                  required
                  value={newProductForm.product_type || ""}
                  onChange={(e) => {
                    handleNewProductInputChange("product_type", e.target.value);
                    // Clear medicine/non-medicine fields when type changes
                    if (e.target.value === "Medicine") {
                      handleNewProductInputChange("boxes", "");
                      handleNewProductInputChange("pieces_per_pack", "");
                      handleNewProductInputChange("total_pieces", "");
                    } else if (e.target.value === "Non-Medicine") {
                      handleNewProductInputChange("boxes", "");
                      handleNewProductInputChange("strips_per_box", "");
                      handleNewProductInputChange("tablets_per_strip", "");
                      handleNewProductInputChange("total_tablets", "");
                    }
                  }}
                  className="flex-1 px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                  style={{
                    borderColor: theme.border.default,
                    backgroundColor: theme.bg.secondary,
                    color: theme.text.primary,
                    focusRingColor: theme.colors.accent
                  }}
                >
                  <option value="">Select Product Type</option>
                  <option value="Medicine">Medicine</option>
                  <option value="Non-Medicine">Non-Medicine</option>
                </select>
                {/* Configure button removed - bulk configuration now integrated into main form */}
              </div>
              
              {/* Product Type Summary */}
              {/* Product Type Summary - Now handled by bulk configuration section below */}
            </div>
            

       
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Suggested Retail Price (SRP) *</label>
              <input
                key="srp_input"
                type="number"
                step="0.01"
                required
                value={newProductForm.srp || ""}
                onChange={(e) => handleNewProductInputChange("srp", e.target.value)}
                placeholder="Enter SRP"
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
            </div>
            {/* Bulk Configuration Fields - Show based on product type and config mode */}
            {newProductForm.product_type && (
              <div className="md:col-span-3">
                <div className="p-4 rounded-lg border" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.default }}>
                  <h4 className="text-sm font-semibold mb-4 flex items-center" style={{ color: theme.text.primary }}>
                    ⚙️ Bulk Configuration
                  </h4>
                  
                  {/* Validation Status Indicator */}
                  <div className="mb-4 p-3 rounded-lg border" style={{
                    backgroundColor: (() => {
                      if (!newProductForm.product_type) return theme.colors.danger + '20'; // red with opacity
                      if (newProductForm.product_type === "Medicine") {
                        if (newProductForm.configMode === "bulk") {
                          return (newProductForm.boxes && newProductForm.strips_per_box && newProductForm.tablets_per_strip) 
                            ? theme.colors.success + '20' // green with opacity
                            : theme.colors.warning + '20'; // yellow with opacity
                        } else if (newProductForm.configMode === "pieces") {
                          return newProductForm.total_tablets ? theme.colors.success + '20' : theme.colors.warning + '20';
                        }
                      } else if (newProductForm.product_type === "Non-Medicine") {
                        if (newProductForm.configMode === "bulk") {
                          return (newProductForm.boxes && newProductForm.pieces_per_pack) 
                            ? theme.colors.success + '20' // green with opacity
                            : theme.colors.warning + '20'; // yellow with opacity
                        } else if (newProductForm.configMode === "pieces") {
                          return newProductForm.total_pieces ? theme.colors.success + '20' : theme.colors.warning + '20';
                        }
                      }
                      return theme.colors.warning + '20'; // yellow with opacity
                    })(),
                    borderColor: (() => {
                      if (!newProductForm.product_type) return theme.colors.danger;
                      if (newProductForm.product_type === "Medicine") {
                        if (newProductForm.configMode === "bulk") {
                          return (newProductForm.boxes && newProductForm.strips_per_box && newProductForm.tablets_per_strip) 
                            ? theme.colors.success
                            : theme.colors.warning;
                        } else if (newProductForm.configMode === "pieces") {
                          return newProductForm.total_tablets ? theme.colors.success : theme.colors.warning;
                        }
                      } else if (newProductForm.product_type === "Non-Medicine") {
                        if (newProductForm.configMode === "bulk") {
                          return (newProductForm.boxes && newProductForm.pieces_per_pack) 
                            ? theme.colors.success
                            : theme.colors.warning;
                        } else if (newProductForm.configMode === "pieces") {
                          return newProductForm.total_pieces ? theme.colors.success : theme.colors.warning;
                        }
                      }
                      return theme.colors.warning;
                    })()
                  }}>
                    <div className="flex items-center justify-between">
                      <div className="flex items-center space-x-2">
                        <span className="text-sm font-medium" style={{
                          color: (() => {
                            if (!newProductForm.product_type) return theme.colors.danger;
                            if (newProductForm.product_type === "Medicine") {
                              if (newProductForm.configMode === "bulk") {
                                return (newProductForm.boxes && newProductForm.strips_per_box && newProductForm.tablets_per_strip) 
                                  ? theme.colors.success
                                  : theme.colors.warning;
                              } else if (newProductForm.configMode === "pieces") {
                                return newProductForm.total_tablets ? theme.colors.success : theme.colors.warning;
                              }
                            } else if (newProductForm.product_type === "Non-Medicine") {
                              if (newProductForm.configMode === "bulk") {
                                return (newProductForm.boxes && newProductForm.pieces_per_pack) 
                                  ? theme.colors.success
                                  : theme.colors.warning;
                              } else if (newProductForm.configMode === "pieces") {
                                return newProductForm.total_pieces ? theme.colors.success : theme.colors.warning;
                              }
                            }
                            return theme.colors.warning;
                          })()
                        }}>
                          {(() => {
                            if (!newProductForm.product_type) return '❌ Select Product Type';
                            if (newProductForm.product_type === "Medicine") {
                              if (newProductForm.configMode === "bulk") {
                                return (newProductForm.boxes && newProductForm.strips_per_box && newProductForm.tablets_per_strip) 
                                  ? '✅ Medicine Configuration Complete' 
                                  : '⏳ Fill Medicine Fields (Boxes, Strips, Tablets)';
                              } else if (newProductForm.configMode === "pieces") {
                                return newProductForm.total_tablets ? '✅ Medicine Configuration Complete' : '⏳ Enter Total Tablets';
                              }
                            } else if (newProductForm.product_type === "Non-Medicine") {
                              if (newProductForm.configMode === "bulk") {
                                return (newProductForm.boxes && newProductForm.pieces_per_pack) 
                                  ? '✅ Non-Medicine Configuration Complete' 
                                  : '⏳ Fill Non-Medicine Fields (Boxes, Pieces)';
                              } else if (newProductForm.configMode === "pieces") {
                                return newProductForm.total_pieces ? '✅ Non-Medicine Configuration Complete' : '⏳ Enter Total Pieces';
                              }
                            }
                            return '⏳ Configure Product';
                          })()}
                        </span>
                      </div>
                      <div className="text-xs" style={{
                        color: (() => {
                          if (!newProductForm.product_type) return theme.colors.danger;
                          if (newProductForm.product_type === "Medicine") {
                            if (newProductForm.configMode === "bulk") {
                              return (newProductForm.boxes && newProductForm.strips_per_box && newProductForm.tablets_per_strip) 
                                ? theme.colors.success
                                : theme.colors.warning;
                            } else if (newProductForm.configMode === "pieces") {
                              return newProductForm.total_tablets ? theme.colors.success : theme.colors.warning;
                            }
                          } else if (newProductForm.product_type === "Non-Medicine") {
                            if (newProductForm.configMode === "bulk") {
                              return (newProductForm.boxes && newProductForm.pieces_per_pack) 
                                ? theme.colors.success
                                : theme.colors.warning;
                            } else if (newProductForm.configMode === "pieces") {
                              return newProductForm.total_pieces ? theme.colors.success : theme.colors.warning;
                            }
                          }
                          return theme.colors.warning;
                        })()
                      }}>
                        {(() => {
                          if (!newProductForm.product_type) return 'Required';
                          if (newProductForm.product_type === "Medicine") {
                            if (newProductForm.configMode === "bulk") {
                              return (newProductForm.boxes && newProductForm.strips_per_box && newProductForm.tablets_per_strip) 
                                ? 'Ready' 
                                : 'Required';
                            } else if (newProductForm.configMode === "pieces") {
                              return newProductForm.total_tablets ? 'Ready' : 'Required';
                            }
                          } else if (newProductForm.product_type === "Non-Medicine") {
                            if (newProductForm.configMode === "bulk") {
                              return (newProductForm.boxes && newProductForm.pieces_per_pack) 
                                ? 'Ready' 
                                : 'Required';
                            } else if (newProductForm.configMode === "pieces") {
                              return newProductForm.total_pieces ? 'Ready' : 'Required';
                            }
                          }
                          return 'Required';
                        })()}
                      </div>
                    </div>
                  </div>
                  
                  {/* Configuration Mode Selection */}
                  <div className="mb-4">
                    <div className="flex items-center space-x-6">
                      <div className="flex items-center space-x-2">
                        <input
                          type="radio"
                          id="newBulkMode"
                          name="newConfigMode"
                          value="bulk"
                          checked={newProductForm.configMode === "bulk"}
                          onChange={(e) => handleNewProductInputChange("configMode", e.target.value)}
                          className="h-4 w-4 rounded"
                          style={{
                            accentColor: theme.colors.accent,
                            borderColor: theme.border.default
                          }}
                        />
                        <label htmlFor="newBulkMode" className="text-sm font-medium" style={{ color: theme.text.primary }}>
                          📦 Bulk Mode (Boxes × Pieces)
                        </label>
                      </div>
                      <div className="flex items-center space-x-2">
                        <input
                          type="radio"
                          id="newPiecesMode"
                          name="newConfigMode"
                          value="pieces"
                          checked={newProductForm.configMode === "pieces"}
                          onChange={(e) => handleNewProductInputChange("configMode", e.target.value)}
                          className="h-4 w-4 rounded"
                          style={{
                            accentColor: theme.colors.accent,
                            borderColor: theme.border.default
                          }}
                        />
                        <label htmlFor="newPiecesMode" className="text-sm font-medium" style={{ color: theme.text.primary }}>
                          🔢 Pieces Mode (Direct Total)
                        </label>
                      </div>
                    </div>
                  </div>

                  {/* Medicine Configuration */}
                  {newProductForm.product_type === "Medicine" && newProductForm.configMode === "bulk" && (
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Number of Boxes *</label>
                        <input
                          type="number"
                          required
                          min="1"
                          value={newProductForm.boxes || ""}
                          onChange={(e) => handleNewProductInputChange("boxes", e.target.value)}
                          className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                          style={{ 
                            borderColor: theme.border.default,
                            backgroundColor: theme.bg.secondary,
                            color: theme.text.primary,
                            focusRingColor: theme.colors.accent
                          }}
                          placeholder="Enter boxes"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Strips per Box *</label>
                        <input
                          type="number"
                          required
                          min="1"
                          value={newProductForm.strips_per_box || ""}
                          onChange={(e) => handleNewProductInputChange("strips_per_box", e.target.value)}
                          className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                          style={{ 
                            borderColor: theme.border.default,
                            backgroundColor: theme.bg.secondary,
                            color: theme.text.primary,
                            focusRingColor: theme.colors.accent
                          }}
                          placeholder="Enter strips per box"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Tablets per Strip *</label>
                        <input
                          type="number"
                          required
                          min="1"
                          value={newProductForm.tablets_per_strip || ""}
                          onChange={(e) => handleNewProductInputChange("tablets_per_strip", e.target.value)}
                          className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                          style={{ 
                            borderColor: theme.border.default,
                            backgroundColor: theme.bg.secondary,
                            color: theme.text.primary,
                            focusRingColor: theme.colors.accent
                          }}
                          placeholder="Enter tablets per strip"
                        />
                      </div>
                    </div>
                  )}

                  {/* Non-Medicine Configuration */}
                  {newProductForm.product_type === "Non-Medicine" && newProductForm.configMode === "bulk" && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Number of Boxes *</label>
                        <input
                          type="number"
                          required
                          min="1"
                          value={newProductForm.boxes || ""}
                          onChange={(e) => handleNewProductInputChange("boxes", e.target.value)}
                          className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                          style={{ 
                            borderColor: theme.border.default,
                            backgroundColor: theme.bg.secondary,
                            color: theme.text.primary,
                            focusRingColor: theme.colors.accent
                          }}
                          placeholder="Enter boxes"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Pieces per Box *</label>
                        <input
                          type="number"
                          required
                          min="1"
                          value={newProductForm.pieces_per_pack || ""}
                          onChange={(e) => handleNewProductInputChange("pieces_per_pack", e.target.value)}
                          className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                          style={{ 
                            borderColor: theme.border.default,
                            backgroundColor: theme.bg.secondary,
                            color: theme.text.primary,
                            focusRingColor: theme.colors.accent
                          }}
                          placeholder="Enter pieces per box"
                        />
                      </div>
                    </div>
                  )}

                  {/* Pieces Mode Configuration */}
                  {newProductForm.configMode === "pieces" && (
                    <div>
                      <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>
                        Total {newProductForm.product_type === "Medicine" ? "Tablets" : "Pieces"} *
                      </label>
                      <input
                        type="number"
                        required
                        min="1"
                        value={newProductForm.product_type === "Medicine" ? (newProductForm.total_tablets || "") : (newProductForm.total_pieces || "")}
                        onChange={(e) => {
                          if (newProductForm.product_type === "Medicine") {
                            handleNewProductInputChange("total_tablets", e.target.value);
                          } else {
                            handleNewProductInputChange("total_pieces", e.target.value);
                          }
                        }}
                        className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                        style={{ 
                          borderColor: theme.border.default,
                          backgroundColor: theme.bg.secondary,
                          color: theme.text.primary,
                          focusRingColor: theme.colors.accent
                        }}
                        placeholder={`Enter total ${newProductForm.product_type === "Medicine" ? "tablets" : "pieces"}`}
                      />
                    </div>
                  )}

                  {/* Auto-computed Total Display */}
                  {newProductForm.configMode === "bulk" && (
                    <div className="mt-4 p-3 rounded border" style={{ backgroundColor: theme.bg.secondary, borderColor: theme.border.default }}>
                      <div className="text-sm" style={{ color: theme.text.primary }}>
                        <span className="font-medium">Auto-computed Total: </span>
                        {newProductForm.product_type === "Medicine" 
                          ? `${newProductForm.total_tablets || 0} tablets`
                          : `${newProductForm.total_pieces || 0} pieces`
                        }
                      </div>
                      <div className="text-xs mt-1" style={{ color: theme.text.muted }}>
                        {newProductForm.product_type === "Medicine" 
                          ? `Formula: ${newProductForm.boxes || 0} boxes × ${newProductForm.strips_per_box || 0} strips × ${newProductForm.tablets_per_strip || 0} tablets`
                          : `Formula: ${newProductForm.boxes || 0} boxes × ${newProductForm.pieces_per_pack || 0} pieces`
                        }
                      </div>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Initial Stock field removed - now handled by bulk configuration fields above */}
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Brand</label>
              <div className="relative">
                <input
                  type="text"
                  placeholder="Type brand name or enter new brand..."
                  value={newProductForm.brand_search || ""}
                  onChange={(e) => {
                    const searchTerm = e.target.value;
                    // "🔍 Brand input:", searchTerm);
                    handleNewProductInputChange("brand_search", searchTerm);
                    
                    // Clear brand_id to indicate this will be a new brand
                    handleNewProductInputChange("brand_id", "");
                  }}
                  className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                  style={{ 
                    borderColor: theme.border.default,
                    backgroundColor: theme.bg.secondary,
                    color: theme.text.primary,
                    focusRingColor: theme.colors.accent
                  }}
                />
                {/* Suggestions dropdown */}
                {newProductForm.brand_search && (
                  <div className="absolute z-10 w-full mt-1 rounded-md shadow-lg max-h-40 overflow-y-auto" style={{ backgroundColor: theme.bg.card, borderColor: theme.border.default, border: `1px solid ${theme.border.default}` }}>
                    {/* Show existing brand suggestions */}
                    {brandsData
                      .filter(brand => 
                        brand.brand.toLowerCase().includes(newProductForm.brand_search.toLowerCase())
                      )
                      .map((brand) => (
                        <div
                          key={brand.brand_id}
                          className="px-3 py-2 cursor-pointer"
                          style={{ 
                            color: theme.text.primary,
                            ':hover': { backgroundColor: theme.bg.hover }
                          }}
                          onMouseEnter={(e) => e.currentTarget.style.backgroundColor = theme.bg.hover}
                          onMouseLeave={(e) => e.currentTarget.style.backgroundColor = 'transparent'}
                          onClick={() => {
                            // "🖱️ Brand suggestion clicked:", brand);
                            handleNewProductInputChange("brand_search", brand.brand);
                            handleNewProductInputChange("brand_id", brand.brand_id);
                          }}
                        >
                          {brand.brand}
                        </div>
                      ))}
                    {/* Show option to create new brand if no exact match */}
                    {!brandsData.find(brand => 
                      brand.brand.toLowerCase() === newProductForm.brand_search.toLowerCase()
                    ) && newProductForm.brand_search.trim() && (
                      <div
                        className="px-3 py-2 cursor-pointer border-t"
                        style={{ 
                          color: theme.text.primary,
                          backgroundColor: theme.colors.success + '20',
                          borderColor: theme.border.default
                        }}
                        onMouseEnter={(e) => e.currentTarget.style.backgroundColor = theme.colors.success + '30'}
                        onMouseLeave={(e) => e.currentTarget.style.backgroundColor = theme.colors.success + '20'}
                        onClick={() => {
                          // "🆕 Creating new brand:", newProductForm.brand_search);
                          handleNewProductInputChange("brand_id", ""); // Clear brand_id to indicate new brand
                        }}
                      >
                        ➕ Create new brand: &ldquo;{newProductForm.brand_search}&rdquo;
                      </div>
                    )}
                  </div>
                )}
              </div>
              <p className="text-xs mt-1" style={{ color: theme.text.muted }}>
                Type to see suggestions or enter a new brand name to create it automatically.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Supplier</label>
              <select
                value={newProductForm.supplier_id || ""}
                onChange={(e) => handleNewProductInputChange("supplier_id", e.target.value)}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              >
                <option value="">Select Supplier</option>
                {suppliersData.map((supplier) => (
                  <option key={supplier.supplier_id} value={supplier.supplier_id}>
                    {supplier.supplier_name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Expiration Date</label>
              <input
                type="date"
                value={newProductForm.expiration || ""}
                onChange={(e) => {
                  const selectedDate = e.target.value;
                  // Validate that the date is not in the past
                  if (selectedDate && new Date(selectedDate) < new Date()) {
                    safeToast("warning", "Expiration date cannot be in the past. Please select a future date.");
                    return;
                  }
                  handleNewProductInputChange("expiration", selectedDate);
                }}
                min={new Date().toISOString().split('T')[0]}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
              <p className="text-xs mt-1" style={{ color: theme.text.muted }}>
                Required for proper FIFO inventory tracking
              </p>
              {newProductForm.expiration && (
                <p className="text-xs mt-1" style={{ color: theme.colors.accent }}>
                  Expires: {new Date(newProductForm.expiration).toLocaleDateString()}
                </p>
              )}
            </div>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Batch Reference</label>
              <input
                type="text"
                value={newProductForm.batch || ""}
                onChange={(e) => {
                  handleNewProductInputChange("batch", e.target.value);
                  setCurrentBatchNumber(e.target.value); // Update current batch number
                }}
                placeholder="Enter batch reference or leave empty for auto-generation"
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
              <p 
                className="text-xs mt-1"
                style={{ color: theme.text.muted }}
              >
                Leave empty to use current batch or enter custom reference
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Order Number</label>
              <input
                type="text"
                value={newProductForm.order_number || ""}
                onChange={(e) => handleNewProductInputChange("order_number", e.target.value)}
                placeholder="Enter order number"
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
            </div>
            <div>
              <label 
                className="block text-sm font-medium mb-1"
                style={{ color: theme.text.secondary }}
              >
                Date Added
              </label>
              <input
                type="date"
                value={newProductForm.date_added || ""}
                readOnly
                className="w-full px-3 py-2 border rounded-md cursor-not-allowed"
                style={{
                  backgroundColor: theme.bg.hover,
                  borderColor: theme.border.default,
                  color: theme.text.primary
                }}
              />
              <p 
                className="text-xs mt-1"
                style={{ color: theme.text.muted }}
              >
                Automatically set to current date
              </p>
            </div>
            <div className="md:col-span-3">
              <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Description</label>
              <textarea
                rows={3}
                value={newProductForm.description || ""}
                onChange={(e) => handleNewProductInputChange("description", e.target.value)}
                className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2"
                style={{ 
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.secondary,
                  color: theme.text.primary,
                  focusRingColor: theme.colors.accent
                }}
              />
            </div>
            <div className="md:col-span-3">
              <div className="flex items-center space-x-6">
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="newPrescription"
                    checked={newProductForm.prescription === 1}
                    onChange={(e) => handleNewProductInputChange("prescription", e.target.checked ? 1 : 0)}
                    className="h-4 w-4 rounded"
                    style={{
                      accentColor: theme.colors.accent,
                      borderColor: theme.border.default
                    }}
                  />
                  <label 
                    htmlFor="newPrescription" 
                    className="text-sm font-medium"
                    style={{ color: theme.text.secondary }}
                  >
                    Prescription Required
                  </label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="newBulk"
                    checked={newProductForm.bulk === 1}
                    onChange={(e) => handleNewProductInputChange("bulk", e.target.checked ? 1 : 0)}
                    className="h-4 w-4 rounded"
                    style={{
                      accentColor: theme.colors.accent,
                      borderColor: theme.border.default
                    }}
                  />
                  <label 
                    htmlFor="newBulk" 
                    className="text-sm font-medium"
                    style={{ color: theme.text.secondary }}
                  >
                    Bulk Product
                  </label>
                </div>
              </div>
            </div>
          </div>

          {/* Summary Section */}
          {newProductForm.product_type && (
            <div 
              className="mt-6 p-4 rounded-lg border"
              style={{
                backgroundColor: theme.bg.hover,
                borderColor: theme.border.default
              }}
            >
              <h4 
                className="text-sm font-semibold mb-3"
                style={{ color: theme.text.primary }}
              >
                Product Summary
              </h4>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div 
                  className="p-3 rounded border"
                  style={{
                    backgroundColor: theme.bg.card,
                    borderColor: theme.border.default
                  }}
                >
                  <h5 
                    className="text-xs font-medium mb-2"
                    style={{ color: theme.text.secondary }}
                  >
                    Bulk Units (for Reports & Inventory)
                  </h5>
                  <p 
                    className="text-sm"
                    style={{ color: theme.text.primary }}
                  >
                    {newProductForm.product_type === "Medicine" 
                      ? `${newProductForm.boxes || 0} boxes`
                      : `${newProductForm.boxes || 0} boxes`
                    }
                  </p>
                </div>
                <div 
                  className="p-3 rounded border"
                  style={{
                    backgroundColor: theme.bg.card,
                    borderColor: theme.border.default
                  }}
                >
                  <h5 
                    className="text-xs font-medium mb-2"
                    style={{ color: theme.text.secondary }}
                  >
                    Total Pieces (for Internal & POS)
                  </h5>
                  <p 
                    className="text-sm"
                    style={{ color: theme.text.primary }}
                  >
                    {newProductForm.product_type === "Medicine" 
                      ? `${newProductForm.total_tablets || 0} tablets`
                      : `${newProductForm.total_pieces || 0} pieces`
                    }
                  </p>
                </div>
              </div>
              <div 
                className="mt-3 text-xs"
                style={{ color: theme.text.muted }}
              >
                <strong>Note:</strong> Reports and inventory display bulk units. Total pieces are used for internal calculations and POS operations.
              </div>
            </div>
          )}

          <div className="flex justify-between items-center mt-6">
            <div className="flex items-center space-x-2">
              {temporaryProducts.length > 0 && (
                <span 
                  className="text-sm"
                  style={{ color: theme.text.muted }}
                >
                  {temporaryProducts.length} product(s) in batch
                </span>
              )}
            </div>
            <div className="flex space-x-4">
              {temporaryProducts.length > 0 && (
                <button
                  type="button"
                  onClick={openBatchEntryModal}
                  className="px-4 py-2 text-white rounded-md flex items-center hover:opacity-80 transition-opacity"
                  style={{ backgroundColor: theme.colors.warning }}
                >
                  <Package className="h-4 w-4 mr-2" />
                  View Batch ({temporaryProducts.length})
                </button>
              )}
              <button
                type="button"
                onClick={closeNewProductModal}
                className="px-4 py-2 border rounded-md hover:opacity-70 transition-opacity"
                style={{
                  borderColor: theme.border.default,
                  backgroundColor: theme.bg.hover,
                  color: theme.text.secondary
                }}
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={loading || (() => {
                  // Basic required fields
                  if (!newProductForm.product_name || !newProductForm.category_id || !newProductForm.product_type || !newProductForm.srp) {
                    return true;
                  }
                  
                  // Product type specific validation
                  if (newProductForm.product_type === "Medicine") {
                    if (newProductForm.configMode === "bulk") {
                      return (!newProductForm.boxes || !newProductForm.strips_per_box || !newProductForm.tablets_per_strip);
                    } else if (newProductForm.configMode === "pieces") {
                      return !newProductForm.total_tablets;
                    }
                  } else if (newProductForm.product_type === "Non-Medicine") {
                    if (newProductForm.configMode === "bulk") {
                      return (!newProductForm.boxes || !newProductForm.pieces_per_pack);
                    } else if (newProductForm.configMode === "pieces") {
                      return !newProductForm.total_pieces;
                    }
                  }
                  
                  return true; // Disable if no product type selected
                })()}
                className="px-4 py-2 text-white rounded-md disabled:opacity-50 hover:opacity-80 transition-opacity"
                style={{ backgroundColor: theme.colors.success }}
                title={(() => {
                  if (!newProductForm.product_name) return "Please enter product name";
                  if (!newProductForm.category_id) return "Please select category";
                  if (!newProductForm.product_type) return "Please select product type";
                  if (!newProductForm.srp) return "Please enter SRP";
                  
                  if (newProductForm.product_type === "Medicine") {
                    if (newProductForm.configMode === "bulk") {
                      if (!newProductForm.boxes) return "Please enter number of boxes";
                      if (!newProductForm.strips_per_box) return "Please enter strips per box";
                      if (!newProductForm.tablets_per_strip) return "Please enter tablets per strip";
                    } else if (newProductForm.configMode === "pieces") {
                      if (!newProductForm.total_tablets) return "Please enter total tablets";
                    }
                  } else if (newProductForm.product_type === "Non-Medicine") {
                    if (newProductForm.configMode === "bulk") {
                      if (!newProductForm.boxes) return "Please enter number of boxes";
                      if (!newProductForm.pieces_per_pack) return "Please enter pieces per box";
                    } else if (newProductForm.configMode === "pieces") {
                      if (!newProductForm.total_pieces) return "Please enter total pieces";
                    }
                  }
                  
                  return "Fill in all required fields to enable button";
                })()}
              >
                {loading ? "Adding..." : "Add to Batch"}
              </button>
            </div>
          </div>
        </form>
          </div>
        </div>
      </div>
    
  )}
  
  
  
        {showFifoModal && selectedProductForFifo && (
          <div className="fixed inset-0 bg-black/30 backdrop-blur-sm flex items-center justify-center z-50">
            <div className="rounded-xl shadow-2xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: theme.bg.card, boxShadow: `0 25px 50px ${theme.shadow}` }}>
              <div className="px-6 py-4 border-b flex items-center justify-between" style={{ borderColor: theme.border.default }}>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>
                  All Batches Details
                </h3>
                <div className="flex items-center gap-2">
                  <button 
                    onClick={loadAllBatches} 
                    className="text-gray-400 hover:text-gray-600 p-1 rounded"
                    title="Refresh batches"
                  >
                    <RefreshCw className="h-5 w-5" />
                  </button>
                  <button onClick={closeFifoModal} className="text-gray-400 hover:text-gray-600">
                    <X className="h-6 w-6" />
                  </button>
                </div>
              </div>

              <div className="p-6">
                <div className="mb-6">
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div className="p-4 rounded-lg" style={{ backgroundColor: theme.colors.info + '20', borderColor: theme.colors.info + '40', border: '1px solid' }}>
                      <h4 className="font-semibold mb-2" style={{ color: theme.colors.info }}>Warehouse Overview</h4>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Total Products: {new Set(allBatchesData.map(b => b.product_id)).size}</p>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Total Batches: {allBatchesData.length}</p>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Total Stock Value: ₱{allBatchesData.reduce((sum, batch) => sum + (Number.parseFloat(batch.srp || 0) * Number.parseFloat(batch.available_quantity || 0)), 0).toFixed(2)}</p>
                    </div>
                    <div className="p-4 rounded-lg" style={{ backgroundColor: theme.colors.success + '20', borderColor: theme.colors.success + '40', border: '1px solid' }}>
                      <h4 className="font-semibold mb-2" style={{ color: theme.colors.success }}>Stock Status</h4>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Status: {getStockStatus(selectedProductForFifo.quantity)}</p>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>SRP: ₱{Number.parseFloat(selectedProductForFifo.srp || 0).toFixed(2)}</p>
                    </div>
                    <div className="p-4 rounded-lg" style={{ backgroundColor: theme.colors.warning + '20', borderColor: theme.colors.warning + '40', border: '1px solid' }}>
                      <h4 className="font-semibold mb-2" style={{ color: theme.colors.warning }}>All Batches Summary</h4>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Total Batches: {allBatchesData.length}</p>
                      <p className="text-sm font-bold" style={{ color: theme.colors.warning }}>
                        Total Available Quantity: {allBatchesData.reduce((sum, batch) => sum + parseInt(batch.available_quantity || 0), 0)} units
                      </p>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Total Value: ₱{allBatchesData.reduce((sum, batch) => sum + (Number.parseFloat(batch.srp || 0) * Number.parseFloat(batch.available_quantity || 0)), 0).toFixed(2)}</p>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Oldest Batch: {allBatchesData.length > 0 ? new Date(allBatchesData[0].batch_date || allBatchesData[0].entry_date).toLocaleDateString() : 'N/A'}</p>
                      <p className="text-sm" style={{ color: theme.text.secondary }}>Newest Batch: {allBatchesData.length > 0 ? new Date(allBatchesData[allBatchesData.length - 1].batch_date || allBatchesData[allBatchesData.length - 1].entry_date).toLocaleDateString() : 'N/A'}</p>
                    </div>
                  </div>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full border-collapse" style={{ color: theme.text.primary, borderColor: theme.border.default, border: `1px solid` }}>
                    <thead>
                      <tr style={{ backgroundColor: theme.bg.secondary }}>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Product Name</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Batch Number</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Batch Reference</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Available Qty</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>SRP</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Total Value</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Category</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Supplier</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Expiration Date</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Days Until Expiry</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Date Added</th>
                        <th className="px-3 py-2 text-left text-sm font-semibold" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.secondary }}>Entry By</th>
                      </tr>
                    </thead>
                    <tbody>
                      {allBatchesData.map((batch, index) => (
                        <tr key={batch.summary_id} style={{ backgroundColor: theme.bg.card }} onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.hover} onMouseLeave={(e) => e.target.style.backgroundColor = theme.bg.card}>
                          <td className="px-3 py-2 font-medium" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.product_name || 'N/A'}
                          </td>
                          <td className="px-3 py-2 font-mono text-sm" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.batch_number || batch.batch_id}
                          </td>
                          <td className="px-3 py-2 font-mono text-xs" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.batch_reference || 'N/A'}
                          </td>
                          <td className="px-3 py-2 text-center" style={{ borderColor: theme.border.default, border: `1px solid` }}>
                            <span className="inline-block px-2 py-1 text-xs font-medium rounded-full" style={{
                              backgroundColor: batch.available_quantity <= 0 ? theme.colors.danger + '20' :
                                             batch.available_quantity <= 10 ? theme.colors.warning + '20' :
                                             theme.colors.success + '20',
                              color: batch.available_quantity <= 0 ? theme.colors.danger :
                                     batch.available_quantity <= 10 ? theme.colors.warning :
                                     theme.colors.success
                            }}>
                              {batch.available_quantity}
                            </span>
                          </td>
                          <td className="px-3 py-2" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.srp && batch.srp > 0 ? `₱${Number.parseFloat(batch.srp).toFixed(2)}` : 'N/A'}
                          </td>
                          <td className="px-3 py-2 font-medium" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            ₱{(Number.parseFloat(batch.srp || 0) * Number.parseFloat(batch.available_quantity || 0)).toFixed(2)}
                          </td>
                          <td className="px-3 py-2" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.category_name || batch.category || 'N/A'}
                          </td>
                          <td className="px-3 py-2" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.supplier_name || 'N/A'}
                          </td>
                          <td className="px-3 py-2 text-center" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A'}
                          </td>
                          <td className="px-3 py-2 text-center" style={{ borderColor: theme.border.default, border: `1px solid` }}>
                            {batch.days_until_expiry !== null ? (
                              (() => {
                                const expiryStatus = getExpiryStatus(batch.expiration_date);
                                return (
                                  <span className="inline-block px-2 py-1 text-xs font-medium rounded-full" style={{
                                    backgroundColor: expiryStatus.status === 'expired' ? theme.colors.danger + '20' :
                                                   expiryStatus.status === 'critical' ? theme.colors.danger + '20' :
                                                   expiryStatus.status === 'warning' ? theme.colors.warning + '20' :
                                                   expiryStatus.status === 'good' ? theme.colors.success + '20' :
                                                   theme.bg.secondary,
                                    color: expiryStatus.status === 'expired' ? theme.colors.danger :
                                           expiryStatus.status === 'critical' ? theme.colors.danger :
                                           expiryStatus.status === 'warning' ? theme.colors.warning :
                                           expiryStatus.status === 'good' ? theme.colors.success :
                                           theme.text.secondary
                                  }}>
                                    {expiryStatus.status === 'expired' ? `Expired ${expiryStatus.days}d ago` : `${expiryStatus.days} days`}
                                    {expiryStatus.status === 'warning' && (
                                      <span className="ml-1 text-xs">⚠️</span>
                                    )}
                                    {expiryStatus.status === 'critical' && (
                                      <span className="ml-1 text-xs">🚨</span>
                                    )}
                                  </span>
                                );
                              })()
                            ) : (
                              <span style={{ color: theme.text.muted }}>N/A</span>
                            )}
                          </td>
                          <td className="px-3 py-2 text-center text-sm" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.batch_date ? new Date(batch.batch_date).toLocaleDateString() : 'N/A'}
                          </td>
                          <td className="px-3 py-2 text-center text-sm" style={{ borderColor: theme.border.default, border: `1px solid`, color: theme.text.primary }}>
                            {batch.entry_by || 'N/A'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {allBatchesData.length === 0 && (
                  <div className="text-center py-8">
                    <Package className="h-12 w-12 text-gray-400 mx-auto mb-4" />
                    <p className="text-gray-500">No batch data available.</p>
                   <p className="text-sm text-gray-400 mt-2">This product may not have batch tracking enabled.</p>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

     
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
                  <button onClick={() => refreshProductData(selectedProductForHistory.product_id)} 
                          className="flex items-center gap-1 px-3 py-1 text-sm rounded-md"
                          style={{ 
                            backgroundColor: theme.colors.accent + '20',
                            color: theme.colors.accent,
                            border: `1px solid ${theme.colors.accent + '40'}`
                          }}
                          onMouseEnter={(e) => {
                            e.target.style.backgroundColor = theme.colors.accent + '30';
                          }}
                          onMouseLeave={(e) => {
                            e.target.style.backgroundColor = theme.colors.accent + '20';
                          }}
                          title="Refresh batch data">
                    <RefreshCw className="h-4 w-4" />
                    Refresh
                  </button>
                  <button onClick={closeQuantityHistoryModal} 
                          style={{ color: theme.text.muted }}
                          onMouseEnter={(e) => e.target.style.color = theme.text.secondary}
                          onMouseLeave={(e) => e.target.style.color = theme.text.muted}>
                    <X className="h-6 w-6" />
                  </button>
                </div>
              </div>

              <div className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                  <div className="p-4 rounded-lg border" 
                       style={{ 
                         backgroundColor: theme.colors.info + '20', 
                         borderColor: theme.colors.info + '40' 
                       }}>
                    <h4 className="font-semibold" style={{ color: theme.colors.info }}>Product Info</h4>
                    <p className="text-sm" style={{ color: theme.text.primary }}>Name: {selectedProductForHistory.product_name}</p>
                    <p className="text-sm" style={{ color: theme.text.primary }}>Category: {selectedProductForHistory.category}</p>
                    <p className="text-sm" style={{ color: theme.text.primary }}>
                      Current Stock: {
                        fifoStockData && fifoStockData.length > 0 
                          ? fifoStockData.reduce((sum, batch) => sum + (batch.available_quantity || 0), 0)
                          : selectedProductForHistory.quantity || 0
                      }
                    </p>
                  </div>
                  <div className="p-4 rounded-lg border" 
                       style={{ 
                         backgroundColor: theme.colors.success + '20', 
                         borderColor: theme.colors.success + '40' 
                       }}>
                    <h4 className="font-semibold" style={{ color: theme.colors.success }}>Stock Status</h4>
                    <p className="text-sm" style={{ color: theme.text.primary }}>
                      Status: {
                        (() => {
                          const currentStock = fifoStockData && fifoStockData.length > 0 
                            ? fifoStockData.reduce((sum, batch) => sum + (batch.available_quantity || 0), 0)
                            : selectedProductForHistory.quantity || 0;
                          
                          if (currentStock <= 0) return 'out of stock';
                          if (currentStock <= 10) return 'low stock';
                          return 'in stock';
                        })()
                      }
                    </p>
                    <p className="text-sm" style={{ color: theme.text.primary }}>SRP: ₱{Number.parseFloat(selectedProductForHistory.srp || 0).toFixed(2)}</p>
                  </div>
                  <div className="p-4 rounded-lg border" 
                       style={{ 
                         backgroundColor: theme.colors.accent + '20', 
                         borderColor: theme.colors.accent + '40' 
                       }}>
                    <h4 className="font-semibold" style={{ color: theme.colors.accent }}>History Summary</h4>
                    <p className="text-sm" style={{ color: theme.text.primary }}>Total Movements: {quantityHistoryData.length}</p>
                    <p className="text-sm" style={{ color: theme.text.primary }}>Last Updated: {quantityHistoryData.length > 0 ? new Date(quantityHistoryData[0].movement_date).toLocaleDateString() : 'N/A'}</p>
                  </div>
                  <div className="p-4 rounded-lg border" 
                       style={{ 
                         backgroundColor: theme.colors.warning + '20', 
                         borderColor: theme.colors.warning + '40' 
                       }}>
                    <h4 className="font-semibold" style={{ color: theme.colors.warning }}>FIFO Batches</h4>
                    <p className="text-sm" style={{ color: theme.text.primary }}>
                      Active Batches: {
                        fifoStockData && fifoStockData.length > 0 
                          ? fifoStockData.filter(batch => (batch.available_quantity || 0) > 0).length
                          : 0
                      }
                    </p>
                    <p className="text-sm font-bold" style={{ color: theme.colors.warning }}>
                      Total Available Quantity: {
                        fifoStockData && fifoStockData.length > 0 
                          ? fifoStockData.reduce((sum, batch) => sum + (batch.available_quantity || 0), 0)
                          : 0
                      } units
                    </p>
                    <p className="text-sm" style={{ color: theme.text.primary }}>
                      Total Value: ₱{
                        fifoStockData && fifoStockData.length > 0 
                          ? fifoStockData.reduce((sum, batch) => sum + ((batch.fifo_srp || batch.srp || batch.unit_cost || 0) * (batch.available_quantity || 0)), 0).toFixed(2)
                          : '0.00'
                      }
                    </p>
                  </div>
                </div>


                {/* Always show Current FIFO Batches */}
                {false ? (
                  // Quantity History Table
                  <div>
                    <h4 className="font-semibold mb-3" style={{ color: theme.text.primary }}>Quantity History</h4>
                    <div className="overflow-x-auto">
                    <table className="w-full border-collapse border" 
                           style={{ 
                             color: theme.text.primary,
                             borderColor: theme.border.default 
                           }}>
                      <thead>
                        <tr style={{ backgroundColor: theme.bg.hover }}>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Batch Reference</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Expiry Date</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Type</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Quantity Change</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Remaining Qty</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>SRP</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Batch Reference</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Notes</th>
                          <th className="border px-3 py-2 text-left text-sm font-semibold" 
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}>Created By</th>
                        </tr>
                      </thead>
                      <tbody>
                        {quantityHistoryData.map((movement, index) => (
                          <tr key={movement.movement_id} 
                              style={{ 
                                backgroundColor: index % 2 === 0 ? 'transparent' : theme.bg.hover + '30'
                              }}
                              onMouseEnter={(e) => e.currentTarget.style.backgroundColor = theme.bg.hover + '50'}
                              onMouseLeave={(e) => e.currentTarget.style.backgroundColor = index % 2 === 0 ? 'transparent' : theme.bg.hover + '30'}>
                            <td className="border px-3 py-2 text-sm font-mono text-blue-600" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>
                              {index + 1}
                            </td>
                            <td className="border px-3 py-2 text-sm" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: movement.expiration_date ? 
                                    (isProductExpired(movement.expiration_date) ? theme.colors.danger :
                                     isProductExpiringSoon(movement.expiration_date) ? theme.colors.warning :
                                     theme.text.primary) : theme.text.primary
                                }}>
                              {movement.expiration_date ? new Date(movement.expiration_date).toLocaleDateString() : 'N/A'}
                            </td>
                            <td className="border px-3 py-2 text-center" 
                                style={{ borderColor: theme.border.default }}>
                              <span className="inline-block px-2 py-1 text-xs font-medium rounded-full"
                                    style={{
                                      backgroundColor: movement.movement_type === 'IN' ? theme.colors.success + '20' : theme.colors.danger + '20',
                                      color: movement.movement_type === 'IN' ? theme.colors.success : theme.colors.danger
                                    }}>
                                {movement.movement_type}
                              </span>
                            </td>
                            <td className="border px-3 py-2 text-center" 
                                style={{ borderColor: theme.border.default }}>
                              <span className="font-medium"
                                    style={{
                                      color: movement.quantity_change > 0 ? theme.colors.success : theme.colors.danger
                                    }}>
                                {movement.quantity_change > 0 ? '+' : ''}{movement.quantity_change}
                              </span>
                            </td>
                            <td className="border px-3 py-2 text-center font-medium" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>
                              {movement.remaining_quantity}
                            </td>
                            <td className="border px-3 py-2" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>
                              ₱{Number.parseFloat(batch.fifo_srp || batch.srp || batch.unit_cost || 0).toFixed(2)}
                            </td>
                            <td className="border px-3 py-2 font-mono text-sm" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>
                              {movement.batch_reference || movement.reference_no || 'N/A'}
                            </td>
                            <td className="border px-3 py-2 text-sm" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.secondary 
                                }}>
                              {movement.notes || 'N/A'}
                            </td>
                            <td className="border px-3 py-2 text-sm" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>
                              {movement.created_by || 'System'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  </div>
                ) : (
                  // Current FIFO Batches Table
                  <div>
                    <h4 className="font-semibold mb-3" style={{ color: theme.text.primary }}>Current FIFO Batches</h4>
                    <div className="overflow-x-auto">
                      <table className="w-full border-collapse border" 
                             style={{ 
                               color: theme.text.primary,
                               borderColor: theme.border.default 
                             }}>
                        <thead>
                          <tr style={{ backgroundColor: theme.bg.hover }}>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Batch Number</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Date Added</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Expiry Date</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Type</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Quantity Change</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Remaining Qty</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>SRP</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Batch Reference</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Notes</th>
                            <th className="border px-3 py-2 text-left text-sm font-semibold" 
                                style={{ 
                                  borderColor: theme.border.default,
                                  color: theme.text.primary 
                                }}>Created By</th>
                          </tr>
                        </thead>
                        <tbody>
                          {fifoStockData && fifoStockData.length > 0 ? (
                            fifoStockData.map((batch, index) => (
                              <tr key={batch.fifo_id || index} 
                                  style={{ 
                                    backgroundColor: index % 2 === 0 ? 'transparent' : theme.bg.hover + '30'
                                  }}
                                  onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.hover + '50'}
                                  onMouseLeave={(e) => e.target.style.backgroundColor = index % 2 === 0 ? 'transparent' : theme.bg.hover + '30'}>
                                <td className="border px-3 py-2 text-sm font-mono text-blue-600" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.primary 
                                    }}>
                                  {index + 1}
                                </td>
                                <td className="border px-3 py-2 text-sm" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.primary 
                                    }}>
                                  {batch.fifo_entry_date ? new Date(batch.fifo_entry_date).toLocaleDateString() : 
                                   batch.batch_date ? new Date(batch.batch_date).toLocaleDateString() : 'N/A'}
                                </td>
                                <td className="border px-3 py-2 text-sm" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: batch.expiration_date ? 
                                        (isProductExpired(batch.expiration_date) ? theme.colors.danger :
                                         isProductExpiringSoon(batch.expiration_date) ? theme.colors.warning :
                                         theme.text.primary) : theme.text.primary
                                    }}>
                                  {batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A'}
                                </td>
                                <td className="border px-3 py-2 text-center" 
                                    style={{ borderColor: theme.border.default }}>
                                  <span className="inline-block px-2 py-1 text-xs font-medium rounded-full"
                                        style={{
                                          backgroundColor: theme.colors.info + '20',
                                          color: theme.colors.info
                                        }}>
                                    FIFO
                                  </span>
                                </td>
                                <td className="border px-3 py-2 text-center" 
                                    style={{ borderColor: theme.border.default }}>
                                  <span className="font-medium"
                                        style={{ color: theme.colors.info }}>
                                    {batch.available_quantity > 0 ? `+${batch.available_quantity}` : '0'}
                                  </span>
                                </td>
                                <td className="border px-3 py-2 text-center font-medium" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.primary 
                                    }}>
                                  {batch.available_quantity || 0}
                                </td>
                                <td className="border px-3 py-2" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.primary 
                                    }}>
                                  ₱{Number.parseFloat(batch.fifo_srp || batch.srp || batch.unit_cost || 0).toFixed(2)}
                                </td>
                                <td className="border px-3 py-2 font-mono text-sm" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.primary 
                                    }}>
                                  {batch.batch_reference || 'N/A'}
                                </td>
                                <td className="border px-3 py-2 text-sm" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.secondary 
                                    }}>
                                  {batch.available_quantity > 0 ? 'Active FIFO batch' : 'Consumed FIFO batch'}
                                </td>
                                <td className="border px-3 py-2 text-sm" 
                                    style={{ 
                                      borderColor: theme.border.default,
                                      color: theme.text.primary 
                                    }}>
                                  {batch.entry_by || 'System'}
                                </td>
                              </tr>
                            ))
                          ) : (
                            <tr>
                              <td colSpan="8" className="border px-3 py-2 text-center" 
                                  style={{ 
                                    borderColor: theme.border.default,
                                    color: theme.text.secondary 
                                  }}>
                                No FIFO batches available
                              </td>
                            </tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
                
                {!showCurrentFifoData && quantityHistoryData.length === 0 && (
                  <div className="text-center py-8">
                    <Package className="h-12 w-12 mx-auto mb-4" style={{ color: theme.text.muted }} />
                    <p style={{ color: theme.text.secondary }}>No quantity history available for this product.</p>
                    <p className="text-sm mt-2" style={{ color: theme.text.muted }}>Quantity changes will appear here after stock updates.</p>
                  </div>
                )}


              </div>
            </div>
          </div>
        )}

    
        {showBatchEntryModal && (
          <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-[99999]">
            <div 
              className="backdrop-blur-md rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto border"
              style={{
                backgroundColor: theme.bg.card,
                borderColor: theme.border.default
              }}
            >
              <div 
                className="px-6 py-4 border-b flex items-center justify-between"
                style={{
                  borderColor: theme.border.default
                }}
              >
                <h3 
                  className="text-lg font-semibold"
                  style={{ color: theme.text.primary }}
                >
                  Batch Entry - {temporaryProducts.length} Product(s)
                </h3>
                <button 
                  onClick={closeBatchEntryModal} 
                  style={{ color: theme.text.muted }}
                  className="hover:opacity-70 transition-opacity"
                >
                  <X className="h-6 w-6" />
                </button>
              </div>

              <div className="p-6">
                {temporaryProducts.length === 0 ? (
                  <div className="text-center py-8">
                    <Package 
                      className="h-12 w-12 mx-auto mb-4" 
                      style={{ color: theme.text.muted }}
                    />
                    <p style={{ color: theme.text.secondary }}>No products in batch yet.</p>
                    <p 
                      className="text-sm mt-2"
                      style={{ color: theme.text.muted }}
                    >
                      Add products using the "Add New Product" modal.
                    </p>
                  </div>
                ) : (
                  <>
                    <div className="mb-6">
                      <div 
                        className="grid grid-cols-1 md:grid-cols-5 gap-4 p-4 rounded-lg"
                        style={{
                          backgroundColor: theme.colors.info + '10'
                        }}
                      >
                        <div className="text-center">
                          <div 
                            className="text-2xl font-bold"
                            style={{ color: theme.colors.info }}
                          >
                            {temporaryProducts.length}
                          </div>
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.info }}
                          >
                            Total Items
                          </div>
                        </div>
                        <div className="text-center">
                          <div 
                            className="text-2xl font-bold"
                            style={{ color: theme.colors.accent }}
                          >
                            {temporaryProducts.filter(p => !p.is_stock_update).length}
                          </div>
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.accent }}
                          >
                            New Products
                          </div>
                        </div>
                        <div className="text-center">
                          <div 
                            className="text-2xl font-bold"
                            style={{ color: theme.colors.success }}
                          >
                            {temporaryProducts.filter(p => p.is_stock_update).length}
                          </div>
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.success }}
                          >
                            Stock Updates
                          </div>
                        </div>
                        <div className="text-center">
                          <div 
                            className="text-2xl font-bold"
                            style={{ color: theme.colors.success }}
                          >
                            {temporaryProducts.reduce((sum, p) => {
                              if (p.is_stock_update) {
                                return sum + parseInt(p.quantity_to_add || 0);
                              }
                              // Calculate quantity based on product type and configuration
                              let totalPieces = 0;
                              if (p.product_type === "Medicine") {
                                totalPieces = parseInt(p.total_tablets || p.quantity || 0);
                              } else if (p.product_type === "Non-Medicine") {
                                totalPieces = parseInt(p.total_pieces || p.quantity || 0);
                              } else {
                                // Fallback to any available quantity field
                                totalPieces = parseInt(p.quantity || p.total_tablets || p.total_pieces || 0);
                              }
                              return sum + totalPieces;
                            }, 0)}
                          </div>
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.success }}
                          >
                            Total Pieces
                          </div>
                        </div>
                        <div className="text-center">
                          <div 
                            className="text-2xl font-bold"
                            style={{ color: theme.colors.warning }}
                          >
                            ₱{temporaryProducts.reduce((sum, p) => {
                              if (p.is_stock_update) {
                                return sum + ((parseFloat(p.new_srp || p.srp || 0)) * parseInt(p.quantity_to_add || 0));
                              }
                              // Calculate quantity based on product type and configuration
                              let totalPieces = 0;
                              if (p.product_type === "Medicine") {
                                totalPieces = parseInt(p.total_tablets || p.quantity || 0);
                              } else if (p.product_type === "Non-Medicine") {
                                totalPieces = parseInt(p.total_pieces || p.quantity || 0);
                              } else {
                                // Fallback to any available quantity field
                                totalPieces = parseInt(p.quantity || p.total_tablets || p.total_pieces || 0);
                              }
                              return sum + ((parseFloat(p.srp || 0) * totalPieces));
                            }, 0).toFixed(2)}
                          </div>
                          <div 
                            className="text-sm"
                            style={{ color: theme.colors.warning }}
                          >
                            Total Value
                          </div>
                        </div>
                      </div>
                    </div>

                    <div className="overflow-x-auto">
                      <table 
                        className="w-full border-collapse border"
                        style={{ borderColor: theme.border.default }}
                      >
                        <thead>
                          <tr style={{ backgroundColor: theme.bg.hover }}>
                            <th 
                              className="border px-3 py-2 text-left text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              #
                            </th>
                            <th 
                              className="border px-3 py-2 text-left text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Product Name
                            </th>
                            <th 
                              className="border px-3 py-2 text-left text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Category
                            </th>
                            <th 
                              className="border px-3 py-2 text-left text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Brand
                            </th>
                            <th 
                              className="border px-3 py-2 text-center text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Quantity
                            </th>
                            <th 
                              className="border px-3 py-2 text-center text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              SRP
                            </th>
                            <th 
                              className="border px-3 py-2 text-center text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Total Value
                            </th>
                            <th 
                              className="border px-3 py-2 text-center text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Expiration
                            </th>
                            <th 
                              className="border px-3 py-2 text-center text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Type
                            </th>
                            <th 
                              className="border px-3 py-2 text-center text-sm font-semibold"
                              style={{ 
                                borderColor: theme.border.default,
                                color: theme.text.primary 
                              }}
                            >
                              Actions
                            </th>
                          </tr>
                        </thead>
                        <tbody>
                          {temporaryProducts.map((product, index) => (
                            <tr 
                              key={product.temp_id} 
                              className="hover:opacity-80 transition-opacity"
                              style={{
                                backgroundColor: product.is_stock_update ? theme.colors.info + '10' : 'transparent'
                              }}
                            >
                              <td 
                                className="border px-3 py-2 text-center font-medium"
                                style={{
                                  borderColor: theme.border.default,
                                  color: theme.text.primary
                                }}
                              >
                                {index + 1}
                              </td>
                              <td 
                                className="border px-3 py-2 font-medium"
                                style={{
                                  borderColor: theme.border.default,
                                  color: theme.text.primary
                                }}
                              >
                                {product.product_name}
                                {product.is_stock_update && (
                                  <span className="ml-2 inline-flex px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                                    Stock Update
                                  </span>
                                )}
                              </td>
                              <td className="border border-gray-300 px-3 py-2">
                                <span className="inline-flex px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full">
                                  {(() => {
                                    // Get category name from category_id
                                    if (product.category_id && categoriesData.length > 0) {
                                      const category = categoriesData.find(cat => cat.category_id == product.category_id);
                                      return category ? category.category_name : `Category ID: ${product.category_id}`;
                                    }
                                    // Fallback to product.category or product.category_name if available
                                    return product.category || product.category_name || "No Category";
                                  })()}
                                </span>
                              </td>
                              <td className="border border-gray-300 px-3 py-2 text-gray-900">
                                {product.brand_search || product.brand_id || product.brand || "N/A"}
                              </td>
                              <td 
                                className="border px-3 py-2 text-center font-medium"
                                style={{
                                  borderColor: theme.border.default,
                                  color: theme.text.primary
                                }}
                              >
                                {product.is_stock_update ? (
                                  <div>
                                    <div className="text-blue-600 font-semibold">+{product.quantity_to_add}</div>
                                    <div className="text-xs text-gray-500">(Adding to existing stock)</div>
                                  </div>
                                ) : (
                                  (() => {
                                    // Calculate quantity based on product type and configuration
                                    if (product.product_type === "Medicine") {
                                      return product.total_tablets || product.quantity || 0;
                                    } else if (product.product_type === "Non-Medicine") {
                                      return product.total_pieces || product.quantity || 0;
                                    } else {
                                      // Fallback to quantity field or calculate from available data
                                      return product.quantity || 
                                             (product.total_tablets || 0) || 
                                             (product.total_pieces || 0) || 0;
                                    }
                                  })()
                                )}
                              </td>
                              <td className="border border-gray-300 px-3 py-2 text-center text-gray-900">
                                ₱{parseFloat(product.new_srp || product.srp || 0).toFixed(2)}
                              </td>
                              <td className="border border-gray-300 px-3 py-2 text-center text-gray-900">
                                ₱{((parseFloat(product.new_srp || product.srp || 0)) * (parseInt(
                                  product.is_stock_update ? product.quantity_to_add : (
                                    (() => {
                                      // Calculate quantity based on product type and configuration
                                      if (product.product_type === "Medicine") {
                                        return product.total_tablets || product.quantity || 0;
                                      } else if (product.product_type === "Non-Medicine") {
                                        return product.total_pieces || product.quantity || 0;
                                      } else {
                                        // Fallback to quantity field or calculate from available data
                                        return product.quantity || 
                                               (product.total_tablets || 0) || 
                                               (product.total_pieces || 0) || 0;
                                      }
                                    })()
                                  )
                                ))).toFixed(2)}
                              </td>
                              <td className="border border-gray-300 px-3 py-2 text-center" 
                                  style={{ 
                                    color: product.expiration ? 
                                      (isProductExpired(product.expiration) ? theme.colors.danger :
                                       isProductExpiringSoon(product.expiration) ? theme.colors.warning :
                                       theme.text.primary) : theme.text.primary
                                  }}>
                                {product.expiration ? new Date(product.expiration).toLocaleDateString() : 'N/A'}
                              </td>
                              <td className="border border-gray-300 px-3 py-2 text-center">
                                {(() => {
                                  const bulk = Number(product.bulk);
                                  const prescription = Number(product.prescription);
                                  if (bulk && prescription) {
                                    return <span className="inline-flex px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">Bulk & Rx</span>;
                                  } else if (bulk) {
                                    return <span className="inline-flex px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">Bulk</span>;
                                  } else if (prescription) {
                                    return <span className="inline-flex px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">Rx</span>;
                                  } else {
                                    return <span className="inline-flex px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded-full">Regular</span>;
                                  }
                                })()}
                              </td>
                              <td className="border border-gray-300 px-3 py-2 text-center">
                                <button
                                  onClick={() => removeTemporaryProduct(product.temp_id)}
                                  className="text-red-600 hover:text-red-900 p-1"
                                  title="Remove from batch"
                                >
                                  <X className="h-4 w-4" />
                                </button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    <div className="flex justify-between items-center mt-6 pt-6 border-t border-gray-200">
                      <div className="text-sm text-gray-600">
                        <p>• Products are stored temporarily until you save the batch</p>
                        <p>• You can add more products or remove existing ones</p>
                        <p>• All products will be saved to database when you click &ldquo;Save Batch&rdquo;</p>
                      </div>
                      <div className="flex space-x-4">
                        <button
                          type="button"
                          onClick={closeBatchEntryModal}
                          className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                          Continue Adding
                        </button>
                        <button
                          type="button"
                          onClick={handleSaveBatch}
                          disabled={loading}
                          className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md disabled:opacity-50"
                        >
                          {loading ? "Saving..." : `Save Batch (${temporaryProducts.length} products)`}
                        </button>
                      </div>
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>
        )}

        {loading && (
          <div className="flex justify-center items-center py-8">
            <svg className="animate-spin h-8 w-8 text-blue-600" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
            </svg>
            <span className="ml-2 text-blue-600">Loading...</span>
          </div>
        )}
          

                                 {showProductTypeModal && (
                   <div className="fixed inset-0 backdrop-blur-sm flex items-center justify-center z-[99999]">
                     <div className="bg-white/95 backdrop-blur-md rounded-xl shadow-2xl p-6 max-w-7xl w-full mx-4 border border-gray-200/50 max-h-[90vh] overflow-y-auto">
                      <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white/95 backdrop-blur-md">
                        <h3 className="text-lg font-semibold text-gray-900">Configure Product Type</h3>
                        <button onClick={closeProductTypeModal} className="text-gray-400 hover:text-gray-600">
                          <X className="h-6 w-6" />
                        </button>
                      </div>

                      <div className="p-6">
                        {/* Product Type Selection */}
                        <div className="mb-6">
                          <label className="block text-sm font-medium text-gray-700 mb-2">Product Type *</label>
                          <select
                            required
                            value={productTypeForm.product_type || ""}
                            onChange={(e) => handleProductTypeInputChange("product_type", e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                          >
                            <option value="">Select Product Type</option>
                            <option value="Medicine">Medicine</option>
                            <option value="Non-Medicine">Non-Medicine</option>
                          </select>
                        </div>

                        {/* Configuration Mode Selection */}
                        {productTypeForm.product_type && (
                          <div className="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <h4 className="text-sm font-semibold text-gray-800 mb-4 flex items-center">
                              ⚙️ Configuration Mode
                            </h4>
                            <div className="flex items-center space-x-6">
                              <div className="flex items-center space-x-2">
                                <input
                                  type="radio"
                                  id="bulkMode"
                                  name="configMode"
                                  value="bulk"
                                  checked={productTypeForm.configMode === "bulk"}
                                  onChange={(e) => handleProductTypeInputChange("configMode", e.target.value)}
                                  className="h-4 w-4 rounded"
                          style={{
                            accentColor: theme.colors.accent,
                            borderColor: theme.border.default
                          }}
                                />
                                <label htmlFor="bulkMode" className="text-sm font-medium text-gray-700">
                                  📦 Bulk Mode (Boxes × Strips/Pieces)
                                </label>
                              </div>
                              <div className="flex items-center space-x-2">
                                <input
                                  type="radio"
                                  id="piecesMode"
                                  name="configMode"
                                  value="pieces"
                                  checked={productTypeForm.configMode === "pieces"}
                                  onChange={(e) => handleProductTypeInputChange("configMode", e.target.value)}
                                  className="h-4 w-4 rounded"
                          style={{
                            accentColor: theme.colors.accent,
                            borderColor: theme.border.default
                          }}
                                />
                                <label htmlFor="piecesMode" className="text-sm font-medium text-gray-700">
                                  🔢 Pieces Mode (Direct Total Input)
                                </label>
                              </div>
                            </div>
                            <p className="text-xs text-gray-600 mt-2">
                              Choose how you want to configure the product quantity
                            </p>
                          </div>
                        )}

                        {/* Medicine Configuration - Bulk Mode */}
                        {productTypeForm.product_type === "Medicine" && productTypeForm.configMode === "bulk" && (
                          <div className="mb-6 p-4 bg-green-50 rounded-lg border border-green-200">
                            <h4 className="text-sm font-semibold text-green-800 mb-4 flex items-center">
                              💊 Medicine Configuration (Bulk Mode)
                            </h4>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Number of Boxes *</label>
                                <input
                                  type="number"
                                  required
                                  min="1"
                                  value={productTypeForm.boxes || ""}
                                  onChange={(e) => handleProductTypeInputChange("boxes", e.target.value)}
                                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                                  placeholder="Enter number of boxes"
                                />
                              </div>
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Strips per Box *</label>
                                <input
                                  type="number"
                                  required
                                  min="1"
                                  value={productTypeForm.strips_per_box || ""}
                                  onChange={(e) => handleProductTypeInputChange("strips_per_box", e.target.value)}
                                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                                  placeholder="Enter strips per box"
                                />
                              </div>
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Tablets per Strip *</label>
                                <input
                                  type="number"
                                  required
                                  min="1"
                                  value={productTypeForm.tablets_per_strip || ""}
                                  onChange={(e) => handleProductTypeInputChange("tablets_per_strip", e.target.value)}
                                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                                  placeholder="Enter tablets per strip"
                                />
                              </div>
                            </div>
                            <div className="mt-4">
                              <label className="block text-sm font-medium text-gray-700 mb-1">Total Tablets (Computed)</label>
                              <input
                                type="text"
                                value={productTypeForm.total_tablets || ""}
                                readOnly
                                className="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-700 cursor-not-allowed"
                                placeholder="Auto-calculated"
                              />
                              <p className="text-xs text-gray-500 mt-1">Automatically computed: Boxes × Strips per Box × Tablets per Strip</p>
                            </div>
                          </div>
                        )}

                        {/* Medicine Configuration - Pieces Mode */}
                        {productTypeForm.product_type === "Medicine" && productTypeForm.configMode === "pieces" && (
                          <div className="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <h4 className="text-sm font-semibold text-blue-800 mb-4 flex items-center">
                              💊 Medicine Configuration (Pieces Mode)
                            </h4>
                            <div className="grid grid-cols-1 gap-4">
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Total Pieces</label>
                                <input
                                  type="number"
                                  required
                                  min="1"
                                  value={productTypeForm.total_tablets || ""}
                                  onChange={(e) => handleProductTypeInputChange("total_tablets", e.target.value)}
                                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="Enter total number of tablets"
                                />
                                <p className="text-xs text-gray-500 mt-1">Direct input of total tablets for this medicine</p>
                              </div>
                            </div>
                          </div>
                        )}

                        {/* Non-Medicine Configuration */}
                        {productTypeForm.product_type === "Non-Medicine" && (
                          <div className="mb-6 p-4 bg-orange-50 rounded-lg border border-orange-200">
                            <h4 className="text-sm font-semibold text-orange-800 mb-4 flex items-center">
                              📦 Non-Medicine Configuration
                            </h4>
                            {productTypeForm.configMode === "bulk" ? (
                              // Bulk Mode: Boxes × Pieces per Box
                              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                  <label className="block text-sm font-medium text-gray-700 mb-1">Number of Boxes *</label>
                                  <input
                                    type="number"
                                    required
                                    min="1"
                                    value={productTypeForm.boxes || ""}
                                    onChange={(e) => handleProductTypeInputChange("boxes", e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"
                                    placeholder="Enter number of boxes"
                                  />
                                </div>
                                <div>
                                  <label className="block text-sm font-medium text-gray-700 mb-1">Pieces per Box *</label>
                                  <input
                                    type="number"
                                    required
                                    min="1"
                                    value={productTypeForm.pieces_per_pack || ""}
                                    onChange={(e) => handleProductTypeInputChange("pieces_per_pack", e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"
                                    placeholder="Enter pieces per box"
                                  />
                                </div>
                              </div>
                            ) : (
                              // Pieces Mode: Direct Total Input
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Total Pieces *</label>
                                <input
                                  type="number"
                                  required
                                  min="1"
                                  value={productTypeForm.total_pieces || ""}
                                  onChange={(e) => handleProductTypeInputChange("total_pieces", e.target.value)}
                                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"
                                  placeholder="Enter total number of pieces"
                                />
                                <p className="text-xs text-gray-500 mt-1">Direct input of total pieces for this product</p>
                              </div>
                            )}
                            
                            {/* Show computed total for bulk mode */}
                            {productTypeForm.configMode === "bulk" && (
                              <div className="mt-4">
                                <label className="block text-sm font-medium text-gray-700 mb-1">Total Pieces (Computed)</label>
                                <input
                                  type="text"
                                  value={productTypeForm.total_pieces || ""}
                                  readOnly
                                  className="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-700 cursor-not-allowed"
                                  placeholder="Auto-calculated"
                                />
                                <p className="text-xs text-gray-500 mt-1">Automatically computed: Boxes × Pieces per Box</p>
                              </div>
                            )}
                          </div>
                        )}

                        {/* Summary Section */}
                        {productTypeForm.product_type && (
                          <div className="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <h4 className="text-sm font-semibold text-blue-900 mb-3">Configuration Summary</h4>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                              <div className="bg-white p-3 rounded border border-blue-200">
                                <h5 className="text-xs font-medium text-blue-800 mb-2">Bulk Units (for Reports & Inventory)</h5>
                                <p className="text-sm text-blue-700">
                                  {productTypeForm.product_type === "Medicine" 
                                    ? `${productTypeForm.boxes || 0} boxes`
                                    : `${productTypeForm.boxes || 0} boxes`
                                  }
                                </p>
                              </div>
                              <div className="bg-white p-3 rounded border border-blue-200">
                                <h5 className="text-xs font-medium text-blue-800 mb-2">Total Pieces (for Internal & POS)</h5>
                                <p className="text-sm text-blue-700">
                                  {productTypeForm.product_type === "Medicine" 
                                    ? `${productTypeForm.total_tablets || 0} tablets`
                                    : `${productTypeForm.total_pieces || 0} pieces`
                                  }
                                </p>
                              </div>
                            </div>
                            <div className="mt-3 text-xs text-blue-600">
                              <strong>Note:</strong> Reports and inventory display bulk units. Total pieces are used for internal calculations and POS operations.
                            </div>
                          </div>
                        )}

                        {/* Action Buttons */}
                        <div className="flex justify-end space-x-4 pt-6 border-t border-gray-200">
                          <button
                            type="button"
                            onClick={closeProductTypeModal}
                            className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
                          >
                            Cancel
                          </button>
                          <button
                            type="button"
                            onClick={saveProductTypeConfiguration}
                            disabled={!productTypeForm.product_type || 
                              (productTypeForm.product_type === "Medicine" && productTypeForm.configMode === "bulk" && (!productTypeForm.boxes || !productTypeForm.strips_per_box || !productTypeForm.tablets_per_strip)) ||
                              (productTypeForm.product_type === "Medicine" && productTypeForm.configMode === "pieces" && !productTypeForm.total_tablets) ||
                              (productTypeForm.product_type === "Non-Medicine" && productTypeForm.configMode === "bulk" && (!productTypeForm.boxes || !productTypeForm.pieces_per_pack)) ||
                              (productTypeForm.product_type === "Non-Medicine" && productTypeForm.configMode === "pieces" && !productTypeForm.total_pieces)
                            }
                            className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
                          >
                            Save Configuration
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                )}

      {/* Expiring Batch Modal */}
      {showExpiringBatchModal && selectedExpiringProduct && (
        <div className="fixed inset-0 bg-black/30 backdrop-blur-sm flex items-center justify-center z-50">
          <div className="rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: theme.bg.card, boxShadow: `0 25px 50px ${theme.shadow}` }}>
            <div className="px-6 py-4 border-b flex items-center justify-between" style={{ borderColor: theme.border.default }}>
              <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>
                Expiring Batch Details - {selectedExpiringProduct.product_name}
              </h3>
              <button onClick={closeExpiringBatchModal} className="text-gray-400 hover:text-gray-600">
                <X className="h-6 w-6" />
              </button>
            </div>

            <div className="p-6">
              {selectedExpiringProduct.expiring_batch ? (
                <div className="space-y-4">
                  <div className="rounded-lg p-4" style={{ backgroundColor: theme.colors.warning + '20', borderColor: theme.colors.warning + '40', border: '1px solid' }}>
                    <h4 className="font-medium mb-2" style={{ color: theme.colors.warning }}>⚠️ Expiring Batch Information</h4>
                    <div className="grid grid-cols-2 gap-4 text-sm">
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Batch Number:</span>
                        <span className="ml-2" style={{ color: theme.text.primary }}>{selectedExpiringProduct.expiring_batch.batch_number || selectedExpiringProduct.expiring_batch.batch_id}</span>
                      </div>
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Batch Reference:</span>
                        <span className="ml-2 font-mono" style={{ color: theme.text.primary }}>{selectedExpiringProduct.expiring_batch.batch_reference}</span>
                      </div>
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Quantity:</span>
                        <span className="ml-2" style={{ color: theme.text.primary }}>{selectedExpiringProduct.expiring_batch.quantity} units</span>
                      </div>
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Days Until Expiry:</span>
                        <span className="ml-2 font-bold" style={{ color: theme.colors.danger }}>{selectedExpiringProduct.expiring_batch.days_until_expiry} days</span>
                      </div>
                    </div>
                  </div>

                  <div className="rounded-lg p-4" style={{ backgroundColor: theme.colors.info + '20', borderColor: theme.colors.info + '40', border: '1px solid' }}>
                    <h4 className="font-medium mb-2" style={{ color: theme.colors.info }}>📋 Product Information</h4>
                    <div className="grid grid-cols-2 gap-4 text-sm">
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Product Name:</span>
                        <span className="ml-2" style={{ color: theme.text.primary }}>{selectedExpiringProduct.product_name}</span>
                      </div>
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Category:</span>
                        <span className="ml-2" style={{ color: theme.text.primary }}>{selectedExpiringProduct.category}</span>
                      </div>
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>Total Stock:</span>
                        <span className="ml-2" style={{ color: theme.text.primary }}>{selectedExpiringProduct.quantity} units</span>
                      </div>
                      <div>
                        <span className="font-medium" style={{ color: theme.text.secondary }}>SRP:</span>
                        <span className="ml-2" style={{ color: theme.text.primary }}>₱{selectedExpiringProduct.srp || selectedExpiringProduct.unit_price || 0}</span>
                      </div>
                    </div>
                  </div>

                  <div className="rounded-lg p-4" style={{ backgroundColor: theme.bg.secondary, borderColor: theme.border.default, border: '1px solid' }}>
                    <h4 className="font-medium mb-2" style={{ color: theme.text.primary }}>💡 Recommended Actions</h4>
                    <ul className="text-sm space-y-1" style={{ color: theme.text.secondary }}>
                      <li>• Consider moving this batch to the front for immediate sale</li>
                      <li>• Check if this batch can be transferred to other locations</li>
                      <li>• Review if the product needs to be discounted for quick sale</li>
                      <li>• Monitor daily sales to ensure this batch is consumed first</li>
                    </ul>
                  </div>

                  <div className="flex justify-end space-x-3">
                    <button
                      onClick={() => {
                        closeExpiringBatchModal();
                        openFifoModal(selectedExpiringProduct);
                      }}
                      className="px-4 py-2 rounded-md transition-colors"
                      style={{ backgroundColor: theme.colors.primary, color: 'white' }}
                      onMouseEnter={(e) => e.target.style.backgroundColor = theme.colors.primary + 'dd'}
                      onMouseLeave={(e) => e.target.style.backgroundColor = theme.colors.primary}
                    >
                      View All Batches
                    </button>
                    <button
                      onClick={closeExpiringBatchModal}
                      className="px-4 py-2 rounded-md transition-colors"
                      style={{ backgroundColor: theme.bg.secondary, color: theme.text.primary, border: `1px solid ${theme.border.default}` }}
                      onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.primary}
                      onMouseLeave={(e) => e.target.style.backgroundColor = theme.bg.secondary}
                    >
                      Close
                    </button>
                  </div>
                </div>
              ) : fifoStockData && fifoStockData.length > 0 ? (
                <div className="space-y-4">
                  <div className="rounded-lg p-4" style={{ backgroundColor: theme.colors.info + '20', borderColor: theme.colors.info + '40', border: '1px solid' }}>
                    <h4 className="font-medium mb-2" style={{ color: theme.colors.info }}>📦 Available Batches</h4>
                    <div className="text-sm" style={{ color: theme.text.secondary }}>
                      Found {fifoStockData.length} batch(es) for this product
                    </div>
                  </div>

                  <div className="rounded-lg p-4" style={{ backgroundColor: theme.bg.secondary, borderColor: theme.border.default, border: '1px solid' }}>
                    <h4 className="font-medium mb-4" style={{ color: theme.text.primary }}>Current FIFO Batches</h4>
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead>
                          <tr style={{ borderBottom: `1px solid ${theme.border.default}` }}>
                            <th className="text-left py-2" style={{ color: theme.text.secondary }}>Batch #</th>
                            <th className="text-left py-2" style={{ color: theme.text.secondary }}>Reference</th>
                            <th className="text-left py-2" style={{ color: theme.text.secondary }}>Quantity</th>
                            <th className="text-left py-2" style={{ color: theme.text.secondary }}>Expiry Date</th>
                            <th className="text-left py-2" style={{ color: theme.text.secondary }}>Days Left</th>
                          </tr>
                        </thead>
                        <tbody>
                          {fifoStockData.map((batch, index) => {
                            const daysLeft = batch.expiration_date ? 
                              Math.ceil((new Date(batch.expiration_date) - new Date()) / (1000 * 60 * 60 * 24)) : 
                              null;
                            return (
                              <tr key={index} style={{ borderBottom: `1px solid ${theme.border.light}` }}>
                                <td className="py-2" style={{ color: theme.text.primary }}>{batch.batch_number || batch.batch_id || index + 1}</td>
                                <td className="py-2 font-mono text-xs" style={{ color: theme.text.primary }}>{batch.batch_reference || 'N/A'}</td>
                                <td className="py-2" style={{ color: theme.text.primary }}>{batch.available_quantity || batch.quantity || 0} units</td>
                                <td className="py-2" style={{ color: theme.text.primary }}>
                                  {batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A'}
                                </td>
                                <td className="py-2">
                                  {daysLeft !== null ? (
                                    <span style={{ 
                                      color: daysLeft < 0 ? theme.colors.danger : 
                                             daysLeft <= 30 ? theme.colors.warning : 
                                             theme.text.primary 
                                    }}>
                                      {daysLeft < 0 ? `Expired ${Math.abs(daysLeft)} days ago` : 
                                       daysLeft === 0 ? 'Expires today' : 
                                       `${daysLeft} days`}
                                    </span>
                                  ) : (
                                    <span style={{ color: theme.text.muted }}>No expiry</span>
                                  )}
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  <div className="flex justify-end space-x-3">
                    <button
                      onClick={() => {
                        closeExpiringBatchModal();
                        openFifoModal(selectedExpiringProduct);
                      }}
                      className="px-4 py-2 rounded-md transition-colors"
                      style={{ backgroundColor: theme.colors.primary, color: 'white' }}
                      onMouseEnter={(e) => e.target.style.backgroundColor = theme.colors.primary + 'dd'}
                      onMouseLeave={(e) => e.target.style.backgroundColor = theme.colors.primary}
                    >
                      View Detailed FIFO
                    </button>
                    <button
                      onClick={closeExpiringBatchModal}
                      className="px-4 py-2 rounded-md transition-colors"
                      style={{ backgroundColor: theme.bg.secondary, color: theme.text.primary, border: `1px solid ${theme.border.default}` }}
                      onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.primary}
                      onMouseLeave={(e) => e.target.style.backgroundColor = theme.bg.secondary}
                    >
                      Close
                    </button>
                  </div>
                </div>
              ) : (
                <div className="text-center py-8">
                  <p style={{ color: theme.text.secondary }}>No batch information available for this product.</p>
                  <button
                    onClick={closeExpiringBatchModal}
                    className="mt-4 px-4 py-2 rounded-md transition-colors"
                    style={{ backgroundColor: theme.bg.secondary, color: theme.text.primary, border: `1px solid ${theme.border.default}` }}
                    onMouseEnter={(e) => e.target.style.backgroundColor = theme.bg.primary}
                    onMouseLeave={(e) => e.target.style.backgroundColor = theme.bg.secondary}
                  >
                    Close
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
            </div>
       
   )
}

export default Warehouse;