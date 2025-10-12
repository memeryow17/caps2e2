"use client";
// Version: 2024-12-19 - Fixed API endpoints
import React, { useState, useEffect } from "react";
import { toast } from "react-toastify";
import { 
  FaPlus, 
  FaSearch, 
  FaEdit, 
  FaTrash, 
  FaEye, 
  FaFilter, 
  FaDownload, 
  FaUpload, 
  FaMinus, 
  FaPlus as FaPlusIcon,
  FaArchive,
  FaBoxOpen
} from "react-icons/fa";
import { Package, TrendingUp, TrendingDown, CheckCircle, Clock, AlertCircle } from "lucide-react";
import { useTheme } from "./ThemeContext";
import apiHandler from "../lib/apiHandler";

const StockAdjustment = () => {
  const { isDarkMode } = useTheme();
  const isDark = isDarkMode;
  const [adjustments, setAdjustments] = useState([]);
  const [filteredAdjustments, setFilteredAdjustments] = useState([]);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedType, setSelectedType] = useState("all");
  const [selectedStatus, setSelectedStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [rowsPerPage] = useState(10);
  
  // Location and user state
  const [currentLocation, setCurrentLocation] = useState(null);
  const [currentUser, setCurrentUser] = useState(null);
  const [userRole, setUserRole] = useState(null);
  
  // Ensure page is always a positive integer
  const setPageSafe = (newPage) => {
    const pageNum = Math.max(1, parseInt(newPage) || 1);
    setPage(pageNum);
  };
  const [editingAdjustment, setEditingAdjustment] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [showModal, setShowModal] = useState(false);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showViewModal, setShowViewModal] = useState(false);
  const [viewingAdjustment, setViewingAdjustment] = useState(null);
  const [stats, setStats] = useState({
    total_adjustments: 0,
    additions: 0,
    subtractions: 0,
    net_quantity: 0
  });
  const [totalRecords, setTotalRecords] = useState(0);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [refreshInterval, setRefreshInterval] = useState(30000); // 30 seconds
  const [lastRefresh, setLastRefresh] = useState(new Date());
  const [newAdjustment, setNewAdjustment] = useState({
    product_id: "",
    product_name: "",
    adjustment_type: "Stock In",
    quantity: 0,
    reason: "",
    notes: "",
    srp: 0,
    expiration_date: ""
  });

  // Batch adjustment states
  const [showBatchAdjustmentModal, setShowBatchAdjustmentModal] = useState(false);
  const [selectedProduct, setSelectedProduct] = useState(null);
  const [productBatches, setProductBatches] = useState([]);
  const [selectedBatch, setSelectedBatch] = useState(null);
  const [batchAdjustment, setBatchAdjustment] = useState({
    batch_id: "",
    batch_reference: "",
    old_qty: 0,
    new_qty: 0,
    adjustment_qty: 0,
    reason: "",
    notes: ""
  });
  const [loadingBatches, setLoadingBatches] = useState(false);
  const [products, setProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [showAdjustmentHistory, setShowAdjustmentHistory] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Helper function to safely parse numbers
  const safeParseFloat = (value, defaultValue = 0) => {
    const parsed = parseFloat(value);
    return isNaN(parsed) ? defaultValue : parsed;
  };

  // API Functions
  const handleApiCall = async (action, data = {}) => {
    try {
      console.log(`🔄 API Call: ${action}`, data);
      // Use the centralized API handler
      const result = await apiHandler.callGenericAPI('inventory_transfer_api.php', action, data);
      return result;
    } catch (error) {
      console.error(`API Error (${action}):`, error);
      throw error;
    }
  };

  // Batch Adjustment API Functions
  const handleBatchApiCall = async (action, data = {}) => {
    try {
      console.log(`🔄 Batch API Call: ${action}`, data);
      // Use the centralized API handler
      const result = await apiHandler.callGenericAPI('batch_stock_adjustment_api.php', action, data);
      return result;
    } catch (error) {
      console.error(`Batch API Error (${action}):`, error);
      throw error;
    }
  };

  // Fetch stock adjustments with comprehensive data
  const fetchAdjustments = async () => {
    setIsLoading(true);
    try {
      console.log('🔄 Fetching batch adjustment data...');
      
      // Use the batch adjustment API to get adjustment history
      const result = await handleBatchApiCall('get_batch_adjustment_history', {
        page: page,
        limit: rowsPerPage
      });
      
      if (result.success) {
        let allData = result.data || [];
        
        console.log('📈 Total records:', allData.length);
        
        // Process the data to match expected format
        const processedData = allData.map(item => ({
          ...item,
          id: item.log_id,
          source: 'batch_adjustment',
          full_date: item.created_at,
          date: item.created_at ? new Date(item.created_at).toLocaleDateString() : '',
          time: item.created_at ? new Date(item.created_at).toLocaleTimeString() : '',
          adjustment_type: item.movement_type === 'IN' ? 'Stock In' : 'Stock Out',
          quantity: Math.abs(item.adjustment_qty),
          reason: item.reason,
          notes: item.notes,
          adjusted_by: item.adjusted_by,
          status: 'Approved', // Batch adjustments are automatically approved
          product_name: item.product_name,
          product_id: item.product_id,
          batch_reference: item.batch_reference,
          expiration_date: item.expiration_date
        }));
        
        setAdjustments(processedData);
        setFilteredAdjustments(processedData);
        setTotalRecords(result.total || processedData.length);
        
        console.log('✅ Batch adjustments fetched successfully:', processedData.length, 'records');
      } else {
        throw new Error(result.message || 'Failed to fetch batch adjustments');
      }
    } catch (error) {
      console.error('❌ Error fetching adjustments:', error);
      toast.error('Failed to fetch adjustments: ' + (error.message || 'Unknown error'));
      setAdjustments([]);
      setFilteredAdjustments([]);
      setTotalRecords(0);
    } finally {
      setIsLoading(false);
    }
  };

  // Fetch statistics
  const fetchStats = async () => {
    try {
      const result = await handleBatchApiCall('get_batch_adjustment_stats');
      if (result.success && result.data) {
        setStats({
          total_adjustments: result.data.total_adjustments || 0,
          additions: result.data.additions || 0,
          subtractions: result.data.subtractions || 0,
          net_quantity: result.data.net_quantity || 0
        });
      } else {
        setStats({
          total_adjustments: 0,
          additions: 0,
          subtractions: 0,
          net_quantity: 0
        });
      }
    } catch (error) {
      console.error('Failed to fetch stats:', error);
      setStats({
        total_adjustments: 0,
        additions: 0,
        subtractions: 0,
        net_quantity: 0
      });
    }
  };

  // Create new adjustment (legacy function - now redirects to batch adjustment)
  const createAdjustment = async (adjustmentData) => {
    toast.info('Please use the Batch Adjustment feature for better tracking');
    setShowCreateModal(false);
    setShowBatchAdjustmentModal(true);
  };

  // Update adjustment (disabled for batch adjustments - they are immutable)
  const updateAdjustment = async (adjustmentData) => {
    toast.warning('Batch adjustments cannot be modified. Please create a new adjustment to correct any errors.');
    setShowModal(false);
    setEditingAdjustment(null);
  };

  // Delete adjustment (disabled for batch adjustments - they are immutable)
  const deleteAdjustment = async (id) => {
    toast.warning('Batch adjustments cannot be deleted. They are permanent records for audit purposes.');
  };

  // Load products for batch adjustment
  const fetchProducts = async () => {
    setLoadingProducts(true);
    try {
      const result = await handleApiCall('get_products_oldest_batch', {
        status: 'active',
        limit: 1000,
        location_id: currentLocation
      });
      
      if (result.success) {
        setProducts(result.data || []);
      }
    } catch (error) {
      console.error('Error fetching products:', error);
      toast.error('Failed to fetch products');
    } finally {
      setLoadingProducts(false);
    }
  };

  // Load batches for selected product
  const fetchProductBatches = async (productId) => {
    setLoadingBatches(true);
    try {
      const result = await handleBatchApiCall('get_product_batches_for_adjustment', {
        product_id: productId,
        location_id: currentLocation
      });
      
      if (result.success) {
        setProductBatches(result.data.batches || []);
        setSelectedProduct(result.data.product);
      }
    } catch (error) {
      console.error('Error fetching product batches:', error);
      toast.error('Failed to fetch product batches');
      setProductBatches([]);
    } finally {
      setLoadingBatches(false);
    }
  };

  // Handle product selection for batch adjustment
  const handleProductSelect = (productId) => {
    const product = products.find(p => p.product_id == productId);
    if (product) {
      setSelectedProduct(product);
      fetchProductBatches(productId);
    }
  };

  // Handle batch adjust button click
  const handleBatchAdjust = (batch) => {
    console.log('🔘 Batch adjust button clicked for batch:', batch);
    setSelectedBatch(batch);
    setBatchAdjustment({
      batch_id: batch.batch_id,
      batch_reference: batch.batch_reference,
      old_qty: batch.current_qty,
      new_qty: batch.current_qty,
      adjustment_qty: 0,
      reason: "",
      notes: ""
    });
    console.log('✅ Batch adjustment state set:', {
      batch_id: batch.batch_id,
      batch_reference: batch.batch_reference,
      old_qty: batch.current_qty,
      new_qty: batch.current_qty,
      adjustment_qty: 0,
      reason: "",
      notes: ""
    });
    setShowBatchAdjustmentModal(true);
  };

  // Handle batch selection
  const handleBatchSelect = (batch) => {
    setSelectedBatch(batch);
    setBatchAdjustment({
      batch_id: batch.batch_id,
      batch_reference: batch.batch_reference,
      old_qty: batch.current_qty,
      new_qty: batch.current_qty,
      adjustment_qty: 0,
      reason: "",
      notes: ""
    });
  };

  // Handle batch adjustment quantity change
  const handleBatchQuantityChange = (newQty) => {
    const oldQty = batchAdjustment.old_qty;
    const adjustmentQty = newQty - oldQty;
    
    console.log('🔢 Quantity change:', {
      oldQty: oldQty,
      newQty: newQty,
      adjustmentQty: adjustmentQty
    });
    
    setBatchAdjustment(prev => ({
      ...prev,
      new_qty: newQty,
      adjustment_qty: adjustmentQty
    }));
  };

  // Create batch adjustment
  const createBatchAdjustment = async () => {
    if (isSubmitting) {
      console.log('🚫 Already submitting, preventing duplicate submission');
      return; // Prevent multiple submissions
    }
    
    console.log('🔄 Starting batch adjustment creation...');
    console.log('📊 Form data:', {
      selectedProduct: selectedProduct,
      selectedBatch: selectedBatch,
      batchAdjustment: batchAdjustment,
      isSubmitting: isSubmitting
    });
    
    // Enhanced validation with detailed error messages
    if (!selectedProduct) {
      toast.error('Please select a product first');
      console.error('❌ No product selected');
      return;
    }
    
    if (!selectedBatch) {
      toast.error('Please select a batch to adjust');
      console.error('❌ No batch selected');
      return;
    }
    
    if (!batchAdjustment.reason) {
      toast.error('Please provide a reason for the adjustment');
      console.error('❌ No reason provided');
      return;
    }
    
    if (batchAdjustment.adjustment_qty === 0) {
      toast.error('Adjustment quantity cannot be zero');
      console.error('❌ Adjustment quantity is zero');
      return;
    }

    setIsSubmitting(true);
    console.log('✅ Validation passed, submitting...');

    try {
      const userData = JSON.parse(sessionStorage.getItem('user_data') || '{}');
      
      const requestData = {
        product_id: selectedProduct.product_id,
        batch_id: batchAdjustment.batch_id,
        old_qty: batchAdjustment.old_qty,
        new_qty: batchAdjustment.new_qty,
        adjustment_qty: batchAdjustment.adjustment_qty,
        reason: batchAdjustment.reason,
        notes: batchAdjustment.notes,
        adjusted_by: userData.username || userData.emp_id || 'inventory_manager'
      };
      
      console.log('📤 Sending request data:', requestData);
      
      const result = await handleBatchApiCall('create_batch_stock_adjustment', requestData);
      
      console.log('📥 Received response:', result);

      if (result.success) {
        toast.success('Batch adjustment created successfully');
        setShowBatchAdjustmentModal(false);
        resetBatchAdjustment();
        
        // Refresh data
        await fetchAdjustments();
        await fetchStats();
      } else {
        toast.error(result.message || 'Failed to create batch adjustment');
        console.error('❌ API returned error:', result);
      }
    } catch (error) {
      console.error('❌ Error creating batch adjustment:', error);
      toast.error('Failed to create batch adjustment: ' + error.message);
    } finally {
      setIsSubmitting(false);
      console.log('🏁 Submission completed');
    }
  };

  // Reset batch adjustment form
  const resetBatchAdjustment = () => {
    setSelectedProduct(null);
    setProductBatches([]);
    setSelectedBatch(null);
    setBatchAdjustment({
      batch_id: "",
      batch_reference: "",
      old_qty: 0,
      new_qty: 0,
      adjustment_qty: 0,
      reason: "",
      notes: ""
    });
  };

  // Initialize user and location data
  useEffect(() => {
    const userData = sessionStorage.getItem('user_data');
    if (userData) {
      const user = JSON.parse(userData);
      setCurrentUser(user.user_id);
      setUserRole(user.role);
      
      // Determine location based on user role (same logic as Warehouse component)
      const locationId = user.role?.toLowerCase() === "admin" ? 2 : 
                        (user.role?.toLowerCase() === "inventory manager" ? 1 : 2);
      setCurrentLocation(locationId);
    }
  }, []);

  // Load data on component mount
  useEffect(() => {
    if (currentLocation !== null) {
      fetchAdjustments();
      fetchStats();
      fetchProducts();
    }
  }, [currentLocation]);

  // Auto-refresh functionality
  useEffect(() => {
    let interval;
    if (autoRefresh) {
      interval = setInterval(() => {
        fetchAdjustments();
        fetchStats();
        setLastRefresh(new Date());
      }, refreshInterval);
    }
    return () => {
      if (interval) clearInterval(interval);
    };
  }, [autoRefresh, refreshInterval]);

  // Refresh data when filters change
  useEffect(() => {
    // Reset to first page when filters change
    if (page !== 1) {
      setPage(1);
    } else {
      fetchAdjustments();
    }
  }, [searchTerm, selectedType, selectedStatus, rowsPerPage]);
  
  // Fetch data when page changes
  useEffect(() => {
    fetchAdjustments();
  }, [page]);

  const getStatusColor = (status) => {
    switch (status) {
      case "Approved":
        return "bg-green-100 text-green-800";
      case "Pending":
        return "bg-yellow-100 text-yellow-800";
      case "Rejected":
        return "bg-red-100 text-red-800";
      default:
        return "bg-gray-100 text-gray-800";
    }
  };

  const getTypeColor = (type) => {
    switch (type) {
      case "Stock In":
      case "Addition":
        return "bg-green-100 text-green-800";
      case "Stock Out":
      case "Subtraction":
        return "bg-red-100 text-red-800";
      default:
        return "bg-gray-100 text-gray-800";
    }
  };

  const getSourceColor = (source) => {
    switch (source) {
      case 'product_update':
        return "bg-yellow-100 text-yellow-800";
      case 'transfer':
        return "bg-green-100 text-green-800";
      case 'stock_adjustment':
        return "bg-blue-100 text-blue-800";
      default:
        return "bg-gray-100 text-gray-800";
    }
  };

  const getSourceIcon = (source) => {
    switch (source) {
      case 'product_update':
        return "🔄";
      case 'transfer':
        return "🚚";
      case 'stock_adjustment':
        return "📝";
      default:
        return "📝";
    }
  };

  const getSourceText = (source) => {
    switch (source) {
      case 'product_update':
        return "Warehouse";
      case 'transfer':
        return "Transfer";
      case 'stock_adjustment':
        return "Adjustment";
      default:
        return "Adjustment";
    }
  };

  const handleEdit = (adjustment) => {
    setEditingAdjustment(adjustment);
    setShowModal(true);
  };

  const handleSave = async () => {
    if (editingAdjustment) {
      await updateAdjustment(editingAdjustment);
    }
  };

  const handleDelete = async (id) => {
    await deleteAdjustment(id);
  };

  const handleView = (adjustment) => {
    setViewingAdjustment(adjustment);
    setShowViewModal(true);
  };

  const handleCreate = async () => {
    if (!newAdjustment.product_id || !newAdjustment.quantity || !newAdjustment.reason) {
      toast.error('Please fill in all required fields');
      return;
    }
    
    await createAdjustment(newAdjustment);
  };

  const adjustmentTypes = ["all", "Stock In", "Stock Out"];
  const statuses = ["all", "Approved", "Pending", "Rejected"];
  
  // Calculate total pages
  const pages = Math.ceil(totalRecords / rowsPerPage);

  return (
    <div className={`p-6 space-y-6 ${isDark ? 'bg-gray-900 text-white' : 'bg-gray-50'}`}>
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className={`text-3xl font-bold ${isDark ? 'text-white' : 'text-gray-800'}`}>Stock Adjustment</h1>
          <p className={`${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Manage inventory adjustments and stock modifications</p>
        </div>
        <div className="flex gap-3">
          <button 
            onClick={() => setShowBatchAdjustmentModal(true)}
            className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700"
          >
            <FaBoxOpen className="h-4 w-4" />
            Batch Adjustment
          </button>
          <button 
            onClick={() => setShowAdjustmentHistory(true)}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
          >
            <FaEye className="h-4 w-4" />
            View Adjustment History
          </button>
        </div>
      </div>

      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl p-6`}>
          <div className="flex items-center">
            <Package className="h-8 w-8 text-blue-500" />
            <div className="ml-4">
              <p className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Total Adjustments</p>
              <p className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{stats.total_adjustments}</p>
            </div>
          </div>
        </div>
        <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl p-6`}>
          <div className="flex items-center">
            <CheckCircle className="h-8 w-8 text-green-500" />
            <div className="ml-4">
              <p className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Additions</p>
              <p className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{stats.additions}</p>
            </div>
          </div>
        </div>
        <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl p-6`}>
          <div className="flex items-center">
            <Clock className="h-8 w-8 text-yellow-500" />
            <div className="ml-4">
              <p className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Subtractions</p>
              <p className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{stats.subtractions}</p>
            </div>
          </div>
        </div>
        <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl p-6`}>
          <div className="flex items-center">
            <TrendingUp className="h-8 w-8 text-purple-500" />
            <div className="ml-4">
              <p className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Net Quantity</p>
              <p className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{stats.net_quantity}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Filters and Search */}
      <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl p-6`}>
        <div className="flex justify-between items-center mb-4">
          <h3 className={`text-lg font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>Filters & Controls</h3>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="autoRefresh"
                checked={autoRefresh}
                onChange={(e) => setAutoRefresh(e.target.checked)}
                className="rounded"
              />
              <label htmlFor="autoRefresh" className={`text-sm ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                Auto-refresh
              </label>
            </div>
            <select
              value={refreshInterval}
              onChange={(e) => setRefreshInterval(parseInt(e.target.value))}
              className={`px-2 py-1 text-sm border rounded ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
            >
              <option value={10000}>10s</option>
              <option value={30000}>30s</option>
              <option value={60000}>1m</option>
              <option value={300000}>5m</option>
            </select>
          </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="md:col-span-2">
            <div className="relative">
              <FaSearch className={`absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} />
              <input
                type="text"
                placeholder="Search adjustments..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className={`pl-10 pr-4 py-2 w-full border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
              />
            </div>
          </div>
          <div>
            <select
              value={selectedType}
              onChange={(e) => setSelectedType(e.target.value)}
              className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
            >
              {adjustmentTypes.map((type) => (
                <option key={type} value={type}>
                  {type === "all" ? "All Types" : type}
                </option>
              ))}
            </select>
          </div>
          <div>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
            >
              {statuses.map((status) => (
                <option key={status} value={status}>
                  {status === "all" ? "All Status" : status}
                </option>
              ))}
            </select>
          </div>
        </div>
        
        {/* Status Bar */}
        <div className={`mt-4 px-4 py-2 rounded-lg ${isDark ? 'bg-gray-700' : 'bg-gray-100'}`}>
          <div className="flex justify-between items-center text-sm">
            <div className="flex items-center gap-4">
              <span className={`${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
                Auto-refresh: {autoRefresh ? 'ON' : 'OFF'}
              </span>
              {autoRefresh && (
                <span className={`${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
                  Interval: {refreshInterval / 1000}s
                </span>
              )}
              <span className={`${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
                Last update: {lastRefresh.toLocaleTimeString()}
              </span>
            </div>
            <div className="flex items-center gap-2">
              {isLoading && (
                <div className="flex items-center gap-2">
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                  <span className={`${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Updating...</span>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Adjustments Table */}
      <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl`}>
        <div className={`px-6 py-4 border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
          <div className="flex justify-between items-center">
            <h3 className={`text-xl font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>Adjustments</h3>
            <div className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
              {isLoading ? 'Loading...' : `${filteredAdjustments.length} adjustments found`}
            </div>
          </div>
        </div>
        <div className="overflow-x-auto max-h-96">
          <table className="w-full min-w-max">
            <thead className={`${isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'} border-b sticky top-0 z-10`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  PRODUCT
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  TYPE
                </th>
                <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  QUANTITY
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  REASON
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  ADJUSTED BY
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  DATE & TIME
                </th>
                <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  STATUS
                </th>
                <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  EXPIRATION
                </th>
                <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  SOURCE
                </th>
                <th className={`px-6 py-3 text-center text-xs font-medium uppercase tracking-wider ${isDark ? 'text-gray-300' : 'text-gray-500'}`}>
                  ACTIONS
                </th>
              </tr>
            </thead>
            <tbody className={`${isDark ? 'bg-gray-800 divide-gray-700' : 'bg-white divide-gray-200'} divide-y`}>
              {isLoading ? (
                <tr>
                  <td colSpan="10" className={`px-6 py-4 text-center ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Loading adjustments...
                  </td>
                </tr>
              ) : filteredAdjustments.length === 0 ? (
                <tr>
                  <td colSpan="10" className={`px-6 py-4 text-center ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    No adjustments found
                  </td>
                </tr>
              ) : (
                filteredAdjustments.map((item, index) => (
                  <tr key={`${item.source || 'unknown'}-${item.id}-${index}`} className={`hover:${isDark ? 'bg-gray-700' : 'bg-gray-50'}`}>
                    <td className="px-6 py-4">
                      <div>
                        <div className={`text-sm font-medium ${isDark ? 'text-white' : 'text-gray-900'}`}>{item.product_name}</div>
                        <div className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>ID: {item.product_id}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-full ${getTypeColor(item.adjustment_type)}`}>
                        {(item.adjustment_type === "Stock In" || item.adjustment_type === "Addition") ? <FaBoxOpen className="h-3 w-3" /> : <FaArchive className="h-3 w-3" />}
                        {item.adjustment_type === "Addition" ? "Stock In" : item.adjustment_type === "Subtraction" ? "Stock Out" : item.adjustment_type}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <div className={`font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{item.quantity}</div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="max-w-xs truncate" title={item.reason}>
                        <span className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{item.reason}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{item.adjusted_by || 'Inventory Manager'}</span>
                    </td>
                    <td className="px-6 py-4">
                      <div>
                        <div className={`text-sm font-medium ${isDark ? 'text-white' : 'text-gray-900'}`}>{item.date}</div>
                        <div className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{item.time}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusColor(item.status)}`}>
                        {item.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <span className={`text-xs ${isDark ? 'text-white' : 'text-gray-900'}`}>
                        {item.expiration_date ? 
                          new Date(item.expiration_date).toLocaleDateString() : 'N/A'
                        }
                      </span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <span className={`inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-full ${getSourceColor(item.source)}`}>
                        {getSourceIcon(item.source)} {getSourceText(item.source)}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <div className="flex justify-center gap-2">
                        <button 
                          onClick={() => handleEdit(item)}
                          className="text-blue-600 hover:text-blue-900 p-1"
                        >
                          <FaEdit className="h-4 w-4" />
                        </button>
                        <button 
                          onClick={() => handleView(item)}
                          className="text-green-600 hover:text-green-900 p-1"
                        >
                          <FaEye className="h-4 w-4" />
                        </button>
                        <button 
                          onClick={() => handleDelete(item.id)}
                          className="text-red-600 hover:text-red-900 p-1"
                        >
                          <FaArchive className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {pages > 1 && (
          <div className="flex justify-center mt-4 pb-4">
            <div className="flex items-center space-x-2">
              <button
                onClick={() => setPageSafe(page - 1)}
                disabled={page === 1}
                className={`px-3 py-1 border rounded disabled:opacity-50 ${isDark ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-700 hover:bg-gray-50'}`}
              >
                Previous
              </button>
              <span className={`px-3 py-1 text-sm ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                Page {page} of {pages}
              </span>
              <button
                onClick={() => setPageSafe(page + 1)}
                disabled={page === pages}
                className={`px-3 py-1 border rounded disabled:opacity-50 ${isDark ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-700 hover:bg-gray-50'}`}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Create Adjustment Modal */}
      {showCreateModal && (
        <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
          <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto`}>
            <div className={`px-6 py-4 border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <div className="flex justify-between items-center">
                <h3 className={`text-xl font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>Create New Adjustment</h3>
                <button 
                  onClick={() => setShowCreateModal(false)}
                  className={`${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'}`}
                >
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div className="p-6">
              <div className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Product ID *</label>
                    <input
                      type="text"
                      value={newAdjustment.product_id}
                      onChange={(e) => setNewAdjustment({...newAdjustment, product_id: e.target.value})}
                      placeholder="Enter product ID"
                      className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                    />
                  </div>
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Product Name</label>
                    <input
                      type="text"
                      value={newAdjustment.product_name}
                      onChange={(e) => setNewAdjustment({...newAdjustment, product_name: e.target.value})}
                      placeholder="Enter product name"
                      className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                    />
                  </div>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Adjustment Type</label>
                    <select
                      value={newAdjustment.adjustment_type}
                      onChange={(e) => setNewAdjustment({...newAdjustment, adjustment_type: e.target.value})}
                      className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                    >
                      <option value="Stock In">Stock In</option>
                      <option value="Stock Out">Stock Out</option>
                    </select>
                  </div>
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Quantity *</label>
                    <input
                      type="number"
                      value={newAdjustment.quantity}
                      onChange={(e) => setNewAdjustment({...newAdjustment, quantity: parseInt(e.target.value) || 0})}
                      min="1"
                      className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                    />
                  </div>
                </div>
                <div>
                  <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Reason *</label>
                  <input
                    type="text"
                    value={newAdjustment.reason}
                    onChange={(e) => setNewAdjustment({...newAdjustment, reason: e.target.value})}
                    placeholder="Enter reason for adjustment"
                    className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                  />
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>SRP</label>
                    <input
                      type="number"
                      step="0.01"
                      value={newAdjustment.srp}
                      onChange={(e) => setNewAdjustment({...newAdjustment, srp: safeParseFloat(e.target.value)})}
                      placeholder="Enter SRP (Suggested Retail Price)"
                      className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                    />
                  </div>
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Expiration Date</label>
                    <input
                      type="date"
                      value={newAdjustment.expiration_date}
                      onChange={(e) => setNewAdjustment({...newAdjustment, expiration_date: e.target.value})}
                      className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      style={{
                        backgroundColor: isDark ? '#374151' : '#ffffff',
                        color: isDark ? '#f9fafb' : '#111827',
                        borderColor: isDark ? '#4b5563' : '#d1d5db'
                      }}
                    />
                  </div>
                </div>
                <div>
                  <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Notes</label>
                  <textarea
                    value={newAdjustment.notes}
                    onChange={(e) => setNewAdjustment({...newAdjustment, notes: e.target.value})}
                    placeholder="Additional notes..."
                    rows={3}
                    className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                  />
                </div>
              </div>
            </div>
            <div className={`px-6 py-4 border-t flex justify-end gap-3 ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <button 
                onClick={() => setShowCreateModal(false)}
                className={`px-4 py-2 ${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-800'}`}
              >
                Cancel
              </button>
              <button 
                onClick={handleCreate}
                className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
              >
                Create Adjustment
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Edit Modal */}
      {showModal && (
        <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
          <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto`}>
            <div className={`px-6 py-4 border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <div className="flex justify-between items-center">
                <h3 className={`text-xl font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>Edit Adjustment</h3>
                <button 
                  onClick={() => setShowModal(false)}
                  className={`${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'}`}
                >
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div className="p-6">
              {editingAdjustment && (
                <div className="space-y-4">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Product Name</label>
                      <input
                        type="text"
                        value={editingAdjustment.product_name || ''}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, product_name: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                    </div>
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Product ID</label>
                      <input
                        type="text"
                        value={editingAdjustment.product_id || ''}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, product_id: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Adjustment Type</label>
                      <select
                        value={editingAdjustment.adjustment_type}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, adjustment_type: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      >
                        <option value="Stock In">Stock In</option>
                        <option value="Stock Out">Stock Out</option>
                      </select>
                    </div>
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Quantity</label>
                      <input
                        type="number"
                        value={editingAdjustment.quantity}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, quantity: parseInt(e.target.value)})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                    </div>
                  </div>
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Reason</label>
                      <input
                        type="text"
                        value={editingAdjustment.reason || ''}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, reason: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>SRP</label>
                      <input
                        type="number"
                        step="0.01"
                        value={editingAdjustment.srp || 0}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, srp: safeParseFloat(e.target.value)})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                    </div>
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Expiration Date</label>
                      <input
                        type="date"
                        value={editingAdjustment.expiration_date || ''}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, expiration_date: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Adjusted By</label>
                      <input
                        type="text"
                        value={editingAdjustment.adjusted_by || ''}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, adjusted_by: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      />
                    </div>
                    <div>
                      <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Status</label>
                      <select
                        value={editingAdjustment.status}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, status: e.target.value})}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      >
                        <option value="Approved">Approved</option>
                        <option value="Pending">Pending</option>
                        <option value="Rejected">Rejected</option>
                      </select>
                    </div>
                  </div>
                  <div>
                    <label className={`block text-sm font-medium mb-1 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>Notes</label>
                      <textarea
                        value={editingAdjustment.notes || ''}
                        onChange={(e) => setEditingAdjustment({...editingAdjustment, notes: e.target.value})}
                        placeholder="Additional notes..."
                        rows={3}
                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                      />
                  </div>
                </div>
              )}
            </div>
            <div className={`px-6 py-4 border-t flex justify-end gap-3 ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <button 
                onClick={() => setShowModal(false)}
                className={`px-4 py-2 ${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-800'}`}
              >
                Cancel
              </button>
              <button 
                onClick={handleSave}
                className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
              >
                Save Changes
              </button>
            </div>
          </div>
        </div>
      )}

      {/* View Modal */}
      {showViewModal && (
        <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
          <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto`}>
            <div className={`px-6 py-4 border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <div className="flex justify-between items-center">
                <h3 className={`text-xl font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>Stock Adjustment Details</h3>
                <button 
                  onClick={() => setShowViewModal(false)}
                  className={`${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'}`}
                >
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div className="p-6">
              {viewingAdjustment && (
                <div className="space-y-6">
                  {/* Header with Type Badge */}
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <span className={`inline-flex items-center gap-1 px-3 py-1 text-sm font-medium rounded-full ${getTypeColor(viewingAdjustment.adjustment_type)}`}>
                        {(viewingAdjustment.adjustment_type === "Stock In" || viewingAdjustment.adjustment_type === "Addition") ? <FaBoxOpen className="h-4 w-4" /> : <FaArchive className="h-4 w-4" />}
                        {viewingAdjustment.adjustment_type === "Addition" ? "Stock In" : viewingAdjustment.adjustment_type === "Subtraction" ? "Stock Out" : viewingAdjustment.adjustment_type}
                      </span>
                      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusColor(viewingAdjustment.status)}`}>
                        {viewingAdjustment.status}
                      </span>
                    </div>
                    <div className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                      {viewingAdjustment.date} at {viewingAdjustment.time}
                    </div>
                  </div>

                  {/* Product Information */}
                  <div className={`${isDark ? 'bg-gray-700' : 'bg-gray-50'} rounded-lg p-4`}>
                    <h4 className={`text-lg font-semibold mb-3 ${isDark ? 'text-white' : 'text-gray-900'}`}>Product Information</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Product Name</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{viewingAdjustment.product_name || 'N/A'}</p>
                      </div>
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Product ID</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{viewingAdjustment.product_id || 'N/A'}</p>
                      </div>
                    </div>
                  </div>

                  {/* Adjustment Details */}
                  <div className={`${isDark ? 'bg-gray-700' : 'bg-gray-50'} rounded-lg p-4`}>
                    <h4 className={`text-lg font-semibold mb-3 ${isDark ? 'text-white' : 'text-gray-900'}`}>Adjustment Details</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Quantity</label>
                        <p className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{viewingAdjustment.quantity}</p>
                      </div>
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>SRP</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>₱{safeParseFloat(viewingAdjustment.srp).toFixed(2)}</p>
                      </div>
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Total Value</label>
                        <p className={`text-lg font-bold text-green-600`}>₱{(safeParseFloat(viewingAdjustment.quantity) * safeParseFloat(viewingAdjustment.srp)).toFixed(2)}</p>
                      </div>
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Expiration Date</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {viewingAdjustment.expiration_date ? 
                            new Date(viewingAdjustment.expiration_date).toLocaleDateString('en-US', {
                              year: 'numeric',
                              month: 'long',
                              day: 'numeric'
                            }) : 'N/A'
                          }
                        </p>
                      </div>
                    </div>
                  </div>

                  {/* Reason and Notes */}
                  <div className={`${isDark ? 'bg-gray-700' : 'bg-gray-50'} rounded-lg p-4`}>
                    <h4 className={`text-lg font-semibold mb-3 ${isDark ? 'text-white' : 'text-gray-900'}`}>Additional Information</h4>
                    <div className="space-y-4">
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Reason</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{viewingAdjustment.reason || 'N/A'}</p>
                      </div>
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Notes</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{viewingAdjustment.notes || 'No additional notes'}</p>
                      </div>
                      <div>
                        <label className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Adjusted By</label>
                        <p className={`text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>{viewingAdjustment.adjusted_by || 'Inventory Manager'}</p>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
            <div className={`px-6 py-4 border-t flex justify-end ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <button 
                onClick={() => setShowViewModal(false)}
                className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Batch Adjustment Modal */}
      {showBatchAdjustmentModal && (
        <div className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50">
          <div className={`${isDark ? 'bg-gray-800' : 'bg-white'} rounded-3xl shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto`}>
            <div className={`px-6 py-4 border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <div className="flex justify-between items-center">
                <div>
                  <h3 className={`text-xl font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>Batch Stock Adjustment</h3>
                  {currentLocation && (
                    <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-600'} mt-1`}>
                      Location: {currentLocation === 2 ? 'Warehouse' : currentLocation === 3 ? 'Pharmacy Store' : currentLocation === 4 ? 'Convenience Store' : `Location ${currentLocation}`}
                    </p>
                  )}
                </div>
                <button 
                  onClick={() => {
                    setShowBatchAdjustmentModal(false);
                    resetBatchAdjustment();
                  }}
                  className={`${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'}`}
                >
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            
            <div className="p-6">
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Left Column - Product Selection */}
                <div className="space-y-4">
                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                      🔍 Search Product Name / SKU / Barcode
                    </label>
                    <div className="relative">
                      <FaSearch className={`absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} />
                      <input
                        type="text"
                        placeholder="Type product name, SKU, or barcode..."
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        className={`pl-10 pr-4 py-2 w-full border rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                      />
                    </div>
                    <select
                      value={selectedProduct?.product_id || ''}
                      onChange={(e) => handleProductSelect(e.target.value)}
                      className={`w-full mt-2 px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      disabled={loadingProducts}
                    >
                      <option value="">Choose a product...</option>
                      {products
                        .filter(product => 
                          !searchTerm || 
                          product.product_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          product.barcode?.toString().includes(searchTerm) ||
                          product.product_id.toString().includes(searchTerm)
                        )
                        .map((product, index) => (
                        <option key={`${product.product_id}-${index}`} value={product.product_id}>
                          {product.product_name} (ID: {product.product_id}) - {product.barcode}
                        </option>
                      ))}
                    </select>
                    {loadingProducts && (
                      <p className={`text-sm mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Loading products...</p>
                    )}
                  </div>

                  {/* Product Info */}
                  {selectedProduct && (
                    <div className={`${isDark ? 'bg-gray-700' : 'bg-gray-50'} rounded-lg p-4`}>
                      <h4 className={`text-lg font-semibold mb-3 ${isDark ? 'text-white' : 'text-gray-900'}`}>Product Information</h4>
                      <div className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                          <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Name:</span>
                          <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedProduct.product_name}</p>
                        </div>
                        <div>
                          <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Barcode:</span>
                          <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedProduct.barcode || 'N/A'}</p>
                        </div>
                        <div>
                          <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Category:</span>
                          <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedProduct.category_name || 'N/A'}</p>
                        </div>
                        <div>
                          <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Total Stock:</span>
                          <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedProduct.quantity || 0} units</p>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Batch List */}
                  {selectedProduct && (
                    <div>
                      <h4 className={`text-lg font-semibold mb-3 ${isDark ? 'text-white' : 'text-gray-900'}`}>📦 Available Batches</h4>
                      {loadingBatches ? (
                        <div className={`text-center py-4 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          Loading batches...
                        </div>
                      ) : productBatches.length === 0 ? (
                        <div className={`text-center py-4 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          No batches available for this product
                        </div>
                      ) : (
                        <div className="overflow-x-auto">
                          <table className="w-full text-sm">
                            <thead className={`${isDark ? 'bg-gray-700' : 'bg-gray-100'} border-b`}>
                              <tr>
                                <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Batch Reference</th>
                                <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Expiry</th>
                                <th className={`px-3 py-2 text-center ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Current Qty</th>
                                <th className={`px-3 py-2 text-center ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Location</th>
                                <th className={`px-3 py-2 text-center ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Date Added</th>
                                <th className={`px-3 py-2 text-center ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Action</th>
                              </tr>
                            </thead>
                            <tbody>
                              {productBatches.map((batch) => (
                                <tr key={batch.batch_id} className={`border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
                                  <td className={`px-3 py-2 font-mono text-xs ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                    {batch.batch_reference}
                                  </td>
                                  <td className={`px-3 py-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                    {batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A'}
                                  </td>
                                  <td className={`px-3 py-2 text-center font-medium ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                    {batch.current_qty} pcs
                                  </td>
                                  <td className={`px-3 py-2 text-center ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                    {batch.location_name || 'Unknown'}
                                  </td>
                                  <td className={`px-3 py-2 text-center text-xs ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                    {batch.batch_created_at ? new Date(batch.batch_created_at).toLocaleDateString() : 'N/A'}
                                  </td>
                                  <td className="px-3 py-2 text-center">
                                    <button
                                      onClick={() => handleBatchAdjust(batch)}
                                      className="flex items-center gap-1 px-2 py-1 text-xs bg-purple-600 text-white rounded hover:bg-purple-700 transition-colors"
                                    >
                                      <FaEdit className="h-3 w-3" />
                                      Adjust
                                    </button>
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {/* Right Column - Adjustment Form */}
                <div className="space-y-4">
                  {selectedBatch ? (
                    <>
                      <div className={`${isDark ? 'bg-gray-700' : 'bg-gray-50'} rounded-lg p-4`}>
                        <h4 className={`text-lg font-semibold mb-3 ${isDark ? 'text-white' : 'text-gray-900'}`}>✏️ Adjustment Form</h4>
                        <div className="grid grid-cols-2 gap-4 text-sm">
                          <div>
                            <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>📦 Product Name:</span>
                            <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedProduct?.product_name}</p>
                          </div>
                          <div>
                            <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>🆔 Batch Reference:</span>
                            <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedBatch.batch_reference}</p>
                          </div>
                          <div>
                            <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>📅 Expiry Date:</span>
                            <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>
                              {selectedBatch.expiration_date ? new Date(selectedBatch.expiration_date).toLocaleDateString() : 'N/A'}
                            </p>
                          </div>
                          <div>
                            <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>🔢 Current Quantity:</span>
                            <p className={`${isDark ? 'text-white' : 'text-gray-900'}`}>{selectedBatch.current_qty} pcs</p>
                          </div>
                        </div>
                      </div>

                      <div>
                        <label className={`block text-sm font-medium mb-2 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                          ✏️ New Quantity OR Adjustment Qty *
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                          <div>
                            <label className={`block text-xs mb-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>New Quantity (Final Count)</label>
                            <input
                              type="number"
                              value={batchAdjustment.new_qty}
                              onChange={(e) => handleBatchQuantityChange(parseInt(e.target.value) || 0)}
                              min="0"
                              className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                            />
                          </div>
                          <div>
                            <label className={`block text-xs mb-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Adjustment Qty (Difference)</label>
                            <input
                              type="number"
                              value={batchAdjustment.adjustment_qty}
                              onChange={(e) => {
                                const adjQty = parseFloat(e.target.value) || 0;
                                const newQty = selectedBatch.current_qty + adjQty;
                                setBatchAdjustment(prev => ({
                                  ...prev,
                                  adjustment_qty: adjQty,
                                  new_qty: Math.max(0, newQty)
                                }));
                              }}
                              className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                              step="0.01"
                            />
                          </div>
                        </div>
                        <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          Current: {selectedBatch.current_qty} → New: {batchAdjustment.new_qty} 
                          {batchAdjustment.adjustment_qty !== 0 && (
                            <span className={`ml-2 ${batchAdjustment.adjustment_qty > 0 ? 'text-green-600' : 'text-red-600'}`}>
                              ({batchAdjustment.adjustment_qty > 0 ? '+' : ''}{batchAdjustment.adjustment_qty})
                            </span>
                          )}
                        </p>
                      </div>

                      <div>
                        <label className={`block text-sm font-medium mb-2 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                          📝 Reason (Dropdown/Text) *
                        </label>
                        <select
                          value={batchAdjustment.reason}
                          onChange={(e) => setBatchAdjustment(prev => ({...prev, reason: e.target.value}))}
                          className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                          required
                        >
                          <option value="">Select a reason...</option>
                          <option value="Physical Count Discrepancy">📉 Physical Count Discrepancy</option>
                          <option value="Expired / Damaged">🗑️ Expired / Damaged</option>
                          <option value="Wrong Entry / Encoding Error">✍️ Wrong Entry / Encoding Error</option>
                          <option value="Return to Supplier">🔁 Return to Supplier</option>
                          <option value="Shrinkage / Theft">📦 Shrinkage / Theft</option>
                          <option value="Other">Other</option>
                        </select>
                        <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          📌 Important: Required field para ma-trace kung bakit may galaw
                        </p>
                      </div>

                      <div>
                        <label className={`block text-sm font-medium mb-2 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                          👤 Adjusted by
                        </label>
                        <div className={`px-3 py-2 border rounded-md ${isDark ? 'border-gray-600 bg-gray-700' : 'border-gray-300 bg-gray-50'}`}>
                          <span className={`${isDark ? 'text-white' : 'text-gray-900'}`}>
                            {(() => {
                              try {
                                const userData = JSON.parse(sessionStorage.getItem('user_data') || '{}');
                                return userData?.username || userData?.emp_id || 'Current User';
                              } catch (e) {
                                return 'Current User';
                              }
                            })()} (Auto-filled)
                          </span>
                        </div>
                      </div>

                      <div>
                        <label className={`block text-sm font-medium mb-2 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                          Additional Notes
                        </label>
                        <textarea
                          value={batchAdjustment.notes}
                          onChange={(e) => setBatchAdjustment(prev => ({...prev, notes: e.target.value}))}
                          rows={3}
                          className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 ${isDark ? 'border-gray-600 bg-gray-700 text-white placeholder-gray-400' : 'border-gray-300 bg-white text-gray-900 placeholder-gray-500'}`}
                          placeholder="Additional details about this adjustment..."
                        />
                      </div>
                    </>
                  ) : (
                    <div className={`text-center py-8 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                      <FaBoxOpen className="h-12 w-12 mx-auto mb-4 opacity-50" />
                      <p>Select a product and batch to make an adjustment</p>
                    </div>
                  )}
                </div>
              </div>
            </div>
            
            <div className={`px-6 py-4 border-t flex justify-end gap-3 ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <button 
                onClick={() => {
                  setShowBatchAdjustmentModal(false);
                  resetBatchAdjustment();
                }}
                className={`px-4 py-2 ${isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-800'}`}
              >
                Cancel
              </button>
              <button 
                onClick={() => {
                  console.log('🔘 Save button clicked');
                  console.log('🔍 Button state check:', {
                    selectedBatch: !!selectedBatch,
                    hasReason: !!batchAdjustment.reason,
                    adjustmentQty: batchAdjustment.adjustment_qty,
                    isSubmitting: isSubmitting,
                    buttonDisabled: !selectedBatch || !batchAdjustment.reason || batchAdjustment.adjustment_qty === 0 || isSubmitting
                  });
                  createBatchAdjustment();
                }}
                disabled={!selectedBatch || !batchAdjustment.reason || batchAdjustment.adjustment_qty === 0 || isSubmitting}
                className="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isSubmitting ? '⏳ Processing...' : '✅ Save Adjustment / Apply'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Adjustment History Modal */}
      {showAdjustmentHistory && (
        <div className="fixed inset-0 flex items-center justify-center z-50">
          <div className={`${isDark ? 'bg-gray-800 border-2 border-blue-400' : 'bg-white border-2 border-blue-500'} rounded-lg shadow-2xl w-full max-w-6xl max-h-[90vh] overflow-hidden ring-4 ring-blue-200`}>
            <div className={`px-6 py-4 border-b flex items-center justify-between ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <h3 className={`text-lg font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                📜 View Adjustment History
              </h3>
              <button
                onClick={() => setShowAdjustmentHistory(false)}
                className={`p-2 rounded-md ${isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-100'}`}
              >
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            
            <div className="p-6">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className={`${isDark ? 'bg-gray-700' : 'bg-gray-100'} border-b`}>
                    <tr>
                      <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Date</th>
                      <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Product</th>
                      <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Batch Reference</th>
                      <th className={`px-3 py-2 text-center ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Old Qty</th>
                      <th className={`px-3 py-2 text-center ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>New Qty</th>
                      <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>Reason</th>
                      <th className={`px-3 py-2 text-left ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>User</th>
                    </tr>
                  </thead>
                  <tbody>
                    {adjustments.slice(0, 20).map((adjustment) => (
                      <tr key={adjustment.id} className={`border-b ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
                        <td className={`px-3 py-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.date} {adjustment.time}
                        </td>
                        <td className={`px-3 py-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.product_name}
                        </td>
                        <td className={`px-3 py-2 font-mono text-xs ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.batch_reference}
                        </td>
                        <td className={`px-3 py-2 text-center ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.old_qty || 0}
                        </td>
                        <td className={`px-3 py-2 text-center ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.new_qty || 0}
                        </td>
                        <td className={`px-3 py-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.reason}
                        </td>
                        <td className={`px-3 py-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {adjustment.adjusted_by}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
            
            <div className={`px-6 py-4 border-t flex justify-end ${isDark ? 'border-gray-700' : 'border-gray-200'}`}>
              <button 
                onClick={() => setShowAdjustmentHistory(false)}
                className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};

export default StockAdjustment; 
