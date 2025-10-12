/**
 * Centralized API Handler for Enguio Project
 * Connects all PHP APIs to frontend components
 */

// Import centralized API configuration
import { API_BASE_URL, API_ENDPOINTS } from './apiConfig';

// API Configuration
const API_CONFIG = {
  BASE_URL: API_BASE_URL,
  USE_PROXY: false, // Set to false to use direct calls
  ENDPOINTS: {
    // Main backend API
    BACKEND: 'backend.php',
    
    // Specific APIs
    SALES: 'sales_api.php',
    TRANSFER: 'transfer_api.php',
    CONVENIENCE: 'convenience_store_api.php',
    PHARMACY: 'pharmacy_api.php',
    get_pharmacy_products_fifo: 'pharmacy_api.php',
    PURCHASE_ORDER: 'purchase_order_api_simple_clean.php',
    STOCK_ADJUSTMENT: 'stock_adjustment_consolidated.php',
    STOCK_SUMMARY: 'stock_summary_api.php',
    POS_RETURN: 'pos_return_api.php',
    FIFO_TRANSFER: 'fifo_transfer_api.php',
    BATCH_TRANSFER: 'batch_transfer_api.php'
  }
};

/**
 * Main API Handler Class
 */
class APIHandler {
  constructor() {
    this.baseUrl = API_CONFIG.BASE_URL;
    this.endpoints = API_CONFIG.ENDPOINTS;
  }

  /**
   * Generic API call method
   * @param {string} endpoint - API endpoint
   * @param {string} action - Action to perform
   * @param {object} data - Data to send
   * @param {string} method - HTTP method (POST/GET)
   * @returns {Promise<object>} API response
   */
  async callAPI(endpoint, action, data = {}, method = 'POST') {
    const url = `${this.baseUrl}/${endpoint}`;
    
    try {
      const config = {
        method: method,
        headers: {
          'Content-Type': 'application/json',
        },
      };

      if (method === 'POST') {
        config.body = JSON.stringify({ action, ...data });
      } else if (method === 'GET' && action) {
        const urlWithAction = `${url}?action=${action}`;
        return this.makeRequest(urlWithAction, config);
      }

      return this.makeRequest(url, config);
    } catch (error) {
      throw error;
    }
  }

  /**
   * Make HTTP request
   */
  async makeRequest(url, config) {
    const response = await fetch(url, config);
    
    if (!response.ok) {
      throw new Error(`HTTP error! Status: ${response.status}`);
    }

    const text = await response.text();
    
    try {
      const result = JSON.parse(text);
      return result;
    } catch (jsonError) {
      throw new Error('Server returned invalid JSON');
    }
  }

  // ============= BACKEND API METHODS =============
  
  async testConnection() {
    return this.callAPI(this.endpoints.BACKEND, 'test_connection');
  }

  async login(credentials) {
    return this.callAPI(this.endpoints.BACKEND, 'login', credentials);
  }

  async getCurrentUser() {
    return this.callAPI(this.endpoints.BACKEND, 'get_current_user');
  }

  async updateAdminEmployeeInfo(employeeData) {
    return this.callAPI(this.endpoints.BACKEND, 'update_admin_employee_info', employeeData);
  }

  async changeAdminPassword(passwordData) {
    return this.callAPI(this.endpoints.BACKEND, 'change_admin_password', passwordData);
  }

  async updateCurrentUserInfo(userData) {
    return this.callAPI(this.endpoints.BACKEND, 'update_current_user_info', userData);
  }

  async changeCurrentUserPassword(passwordData) {
    return this.callAPI(this.endpoints.BACKEND, 'change_current_user_password', passwordData);
  }

  async getCategories() {
    return this.callAPI(this.endpoints.BACKEND, 'get_categories');
  }

  async getLocations() {
    return this.callAPI(this.endpoints.BACKEND, 'get_locations');
  }

  async getProducts(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_products', filters);
  }

  async getSuppliers() {
    return this.callAPI(this.endpoints.BACKEND, 'get_suppliers');
  }

  async getWarehouseData(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_warehouse_data', filters);
  }

  async getWarehouseKPIs(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_warehouse_kpis', filters);
  }

  async getWarehouseSupplyByProduct(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_warehouse_supply_by_product', filters);
  }

  async getWarehouseSupplyByLocation(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_warehouse_supply_by_location', filters);
  }

  async getWarehouseStockoutItems(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_warehouse_stockout_items', filters);
  }

  async getWarehouseProductKPIs(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_warehouse_product_kpis', filters);
  }

  async getTopProductsByQuantity(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_top_products_by_quantity', filters);
  }

  async getStockDistributionByCategory(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_stock_distribution_by_category', filters);
  }

  async getFastMovingItemsTrend(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_fast_moving_items_trend', filters);
  }

  async getCriticalStockAlerts(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_critical_stock_alerts', filters);
  }

  async getInventoryByBranchCategory(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_inventory_by_branch_category', filters);
  }

  async getProductsByLocationName(filters = {}) {
    return this.callAPI(this.endpoints.BACKEND, 'get_products_by_location_name', filters);
  }

  // ============= SALES API METHODS =============
  
  async getPOSSales(filters = {}) {
    return this.callAPI(this.endpoints.SALES, 'get_pos_sales', filters);
  }

  async savePOSSale(saleData) {
    return this.callAPI(this.endpoints.SALES, 'save_pos_sale', saleData);
  }

  async checkBarcode(barcode) {
    return this.callAPI(this.endpoints.SALES, 'check_barcode', { barcode });
  }

  async getDiscounts() {
    return this.callAPI(this.endpoints.SALES, 'get_discounts');
  }

  async updateProductStock(productData) {
    return this.callAPI(this.endpoints.SALES, 'update_product_stock', productData);
  }

  // ============= TRANSFER API METHODS =============
  
  async getTransfers(filters = {}) {
    return this.callAPI(this.endpoints.TRANSFER, 'get_transfers_with_details', filters);
  }

  async createTransfer(transferData) {
    return this.callAPI(this.endpoints.TRANSFER, 'create_transfer', transferData);
  }

  async getFIFOStock(productId, locationId) {
    return this.callAPI(this.endpoints.TRANSFER, 'get_fifo_stock', { product_id: productId, location_id: locationId });
  }

  async getBatchTransferDetails(transferId) {
    return this.callAPI(this.endpoints.TRANSFER, 'get_batch_transfer_details', { transfer_id: transferId });
  }

  // ============= CONVENIENCE STORE API METHODS =============
  
  async getConvenienceProducts(filters = {}) {
    return this.callAPI(this.endpoints.CONVENIENCE, 'get_products', filters);
  }

  async getConvenienceProductsFIFO(filters = {}) {
    return this.callAPI(this.endpoints.CONVENIENCE, 'get_convenience_products_fifo', filters);
  }

  async updateConvenienceStock(stockData) {
    return this.callAPI(this.endpoints.CONVENIENCE, 'update_stock', stockData);
  }

  async getConvenienceNotifications() {
    return this.callAPI(this.endpoints.CONVENIENCE, 'get_notifications');
  }

  async archiveConvenienceProduct(productId) {
    return this.callAPI(this.endpoints.CONVENIENCE, 'archive_product', { product_id: productId });
  }

  // ============= PHARMACY API METHODS =============
  
  async getPharmacyProducts(filters = {}) {
    return this.callAPI(this.endpoints.PHARMACY, 'get_pharmacy_products', filters);
  }

  async getPharmacyProductsFIFO(filters = {}) {
    return this.callAPI(this.endpoints.PHARMACY, 'get_pharmacy_products_fifo', filters);
  }

  async getPharmacyProductsFixed(filters = {}) {
    return this.callAPI('fix_pharmacy_apis.php', 'get_pharmacy_products_fixed', filters);
  }

  async getPharmacyKPIsFixed(filters = {}) {
    return this.callAPI('fix_pharmacy_apis.php', 'get_pharmacy_kpis_fixed', filters);
  }

  async getPharmacyProductsFromBatch(filters = {}) {
    return this.callAPI('batch_tracking.php', 'get_pharmacy_products', filters);
  }

  async getPharmacyKPIs(filters = {}) {
    return this.callAPI(this.endpoints.PHARMACY, 'get_pharmacy_kpis', filters);
  }

  async getPharmacyStockSummary(filters = {}) {
    return this.callAPI(this.endpoints.PHARMACY, 'get_stock_summary', filters);
  }

  async updatePharmacyStock(stockData) {
    return this.callAPI(this.endpoints.PHARMACY, 'update_stock', stockData);
  }

  async getPharmacyNotifications() {
    return this.callAPI(this.endpoints.PHARMACY, 'get_notifications');
  }

  async archivePharmacyProduct(productId) {
    return this.callAPI(this.endpoints.PHARMACY, 'archive_product', { product_id: productId });
  }

  // ============= PURCHASE ORDER API METHODS =============
  
  async createPurchaseOrder(orderData) {
    return this.callAPI(this.endpoints.PURCHASE_ORDER, 'create_purchase_order', orderData);
  }

  async getPurchaseOrders(filters = {}) {
    return this.callAPI(this.endpoints.PURCHASE_ORDER, 'purchase_orders', filters);
  }

  async getPurchaseOrderDetails(orderId) {
    return this.callAPI(this.endpoints.PURCHASE_ORDER, 'purchase_order_details', { order_id: orderId });
  }

  async updatePOStatus(orderId, status) {
    return this.callAPI(this.endpoints.PURCHASE_ORDER, 'update_po_status', { order_id: orderId, status });
  }

  async requestMissingItems(requestData) {
    return this.callAPI(this.endpoints.PURCHASE_ORDER, 'request_missing_items', requestData);
  }

  async updatePartialDelivery(deliveryData) {
    return this.callAPI(this.endpoints.PURCHASE_ORDER, 'update_partial_delivery', deliveryData);
  }

  // ============= STOCK ADJUSTMENT API METHODS =============
  
  async getAllStockAdjustmentData(dateRange = {}) {
    return this.callAPI(this.endpoints.STOCK_ADJUSTMENT, 'get_all_stock_adjustment_data', dateRange);
  }

  async createStockAdjustment(adjustmentData) {
    return this.callAPI(this.endpoints.STOCK_ADJUSTMENT, 'create_adjustment', adjustmentData);
  }

  async updateStockAdjustment(adjustmentId, updateData) {
    return this.callAPI(this.endpoints.STOCK_ADJUSTMENT, 'update_adjustment', { adjustment_id: adjustmentId, ...updateData });
  }

  // ============= STOCK SUMMARY API METHODS =============
  
  async getStockSummary(filters = {}) {
    return this.callAPI(this.endpoints.STOCK_SUMMARY, 'get_stock_summary', filters);
  }

  async getStockMovements(filters = {}) {
    return this.callAPI(this.endpoints.STOCK_SUMMARY, 'get_stock_movements', filters);
  }

  // ============= POS RETURN API METHODS =============
  
  async processCustomerReturn(returnData) {
    return this.callAPI(this.endpoints.POS_RETURN, 'process_customer_return', returnData);
  }

  async approveReturn(returnId, approvalData) {
    return this.callAPI(this.endpoints.POS_RETURN, 'approve_return', { return_id: returnId, ...approvalData });
  }

  async rejectReturn(returnId, rejectionData) {
    return this.callAPI(this.endpoints.POS_RETURN, 'reject_return', { return_id: returnId, ...rejectionData });
  }

  async getPendingReturns() {
    return this.callAPI(this.endpoints.POS_RETURN, 'get_pending_returns');
  }

  // ============= FIFO TRANSFER API METHODS =============
  
  async executeFIFOTransfer(transferData) {
    return this.callAPI(this.endpoints.FIFO_TRANSFER, 'transfer', transferData);
  }

  async checkFIFOStock(productId, locationId) {
    return this.callAPI(this.endpoints.FIFO_TRANSFER, 'check_stock', { product_id: productId, location_id: locationId });
  }

  async getFIFOTransferHistory(filters = {}) {
    return this.callAPI(this.endpoints.FIFO_TRANSFER, 'transfer_history', filters);
  }

  async getAvailableProducts() {
    return this.callAPI(this.endpoints.FIFO_TRANSFER, 'available_products');
  }

  // ============= BATCH TRANSFER API METHODS =============
  
  async createBatchTransfer(batchData) {
    return this.callAPI(this.endpoints.BATCH_TRANSFER, 'create_batch_transfer', batchData);
  }

  async getBatchTransfers(filters = {}) {
    return this.callAPI(this.endpoints.BATCH_TRANSFER, 'get_batch_transfers', filters);
  }

  async updateBatchTransfer(transferId, updateData) {
    return this.callAPI(this.endpoints.BATCH_TRANSFER, 'update_batch_transfer', { transfer_id: transferId, ...updateData });
  }

  // ============= GENERIC API CALL METHOD =============
  
  /**
   * Generic method to call any API endpoint with any action
   * @param {string} endpoint - API endpoint file name
   * @param {string} action - Action to perform
   * @param {object} data - Data to send
   * @param {string} method - HTTP method (POST/GET)
   * @returns {Promise<object>} API response
   */
  async callGenericAPI(endpoint, action, data = {}, method = 'POST') {
    return this.callAPI(endpoint, action, data, method);
  }
}

// Create singleton instance
const apiHandler = new APIHandler();

// Export both the class and instance
export { APIHandler, apiHandler as default };

// Action-to-endpoint mapping for modular API
export const getApiEndpointForAction = (action) => {
  // Map action names to their respective modular API endpoints
  // Expand this mapping as needed for all supported actions
  const actionMap = {
    // ============= AUTHENTICATION & USER MANAGEMENT =============
    login: 'login.php',
    logout: 'backend.php',
    generate_captcha: 'backend.php',
    add_employee: 'backend.php',
    display_employee: 'backend.php',
    update_employee_status: 'backend.php',
    get_login_records: 'backend.php',
    get_users: 'backend.php',
    get_activity_records: 'backend.php',
    register_terminal_route: 'backend.php',
    get_login_activity: 'backend.php',
    get_login_activity_count: 'backend.php',
    log_activity: 'backend.php',
    get_activity_logs: 'backend.php',
    get_all_logs: 'backend.php',
    get_current_user: 'backend.php',
    reset_password: 'backend.php',

    // ============= PRODUCT MANAGEMENT =============
    add_product: 'backend.php',
    update_product: 'backend.php',
    delete_product: 'backend.php',
    get_products: 'backend.php',
    get_products_by_location: 'backend.php',
    get_products_by_location_name: 'backend.php',
    get_location_products: 'backend.php',
    get_products_oldest_batch: 'backend.php',
    get_products_oldest_batch_for_transfer: 'backend.php',
    get_product_quantities: 'backend.php',
    add_quantity_to_product: 'backend.php',
    duplicate_product_batches: 'backend.php',
    
    // Convenience Store Products
    add_convenience_product: 'convenience_store_api.php',
    get_convenience_products: 'convenience_store_api.php',
    get_convenience_products_fifo: 'convenience_store_api.php',
    get_convenience_batch_details: 'convenience_store_api.php',
    
    // Pharmacy Products
    add_pharmacy_product: 'pharmacy_api.php',
    get_pharmacy_products: 'pharmacy_api.php',
    get_pharmacy_products_fifo: 'pharmacy_api.php',
    
    // Brand Management
    addBrand: 'backend.php',
    displayBrand: 'backend.php',
    deleteBrand: 'backend.php',
    add_brand: 'backend.php',
    get_brands: 'backend.php',
    
    // Supplier Management
    add_supplier: 'backend.php',
    update_supplier: 'backend.php',
    delete_supplier: 'backend.php',
    deleteSupplier: 'backend.php',
    get_suppliers: 'backend.php',
    restoreSupplier: 'backend.php',
    displayArchivedSuppliers: 'backend.php',
    
    // Categories & Locations
    get_categories: 'backend.php',
    get_locations: 'backend.php',
    get_inventory_staff: 'backend.php',
    get_locations_for_filter: 'backend.php',

    // ============= INVENTORY & TRANSFERS =============
    create_transfer: 'backend.php',
    update_transfer_status: 'backend.php',
    delete_transfer: 'backend.php',
    get_transfers_with_details: 'backend.php',
    get_transferred_products_by_location: 'backend.php',
    get_transfer_logs: 'backend.php',
    get_transfer_log: 'backend.php',
    get_transfer_log_by_id: 'backend.php',
    create_transfer_batch_details_table: 'backend.php',
    get_transfer_batch_details: 'batch_transfer_api.php',
    get_inventory: 'inventory_api.php',
    update_inventory: 'inventory_api.php',
    
    // Batch Management
    get_batches: 'batch_tracking.php',
    update_batch: 'backend.php',
    add_batch_entry: 'backend.php',
    get_all_batches: 'backend.php',
    get_batch_transfers_by_location: 'convenience_store_api.php',
    sync_transferred_products: 'convenience_store_api.php',
    get_transferred_batches: 'get_transferred_batches_api.php',
    
    // FIFO Management
    get_fifo_stock: 'backend.php',
    consume_stock_fifo: 'backend.php',
    transfer_fifo_consumption: 'backend.php',
    enhanced_fifo_transfer: 'fifo_transfer_api.php',
    get_fifo_stock_status: 'backend.php',
    check_fifo_availability: 'backend.php',
    sync_fifo_stock: 'backend.php',
    force_sync_all_products: 'backend.php',
    cleanup_duplicate_transfer_products: 'backend.php',
    
    // Movement History
    get_movement_history: 'backend.php',
    get_quantity_history: 'backend.php',

    // ============= POS & SALES =============
    get_pos_products: 'sales_api.php',
    check_barcode: 'backend_modular.php',  // Routes to Api/modules/barcode.php
    check_product_name: 'sales_api.php',
    get_product_batches: 'sales_api.php',
    get_discounts: 'sales_api.php',
    update_product_stock: 'backend.php', // Fixed: Route to backend.php for full FIFO/batch support
    reduce_product_stock: 'sales_api.php',
    simple_update_product_stock: 'sales_api.php',
    
    // POS Returns & Exchanges
    create_return: 'pos_return_api.php',
    get_returns: 'pos_return_api.php',
    approve_return: 'pos_return_api.php',
    reject_return: 'pos_return_api.php',
    create_exchange: 'pos_exchange_api.php',
    get_exchanges: 'pos_exchange_api.php',

    // ============= REPORTS & ANALYTICS =============
    get_inventory_kpis: 'backend.php',
    get_supply_by_location: 'backend.php',
    get_return_rate_by_product: 'backend.php',
    get_stockout_items: 'backend.php',
    get_product_kpis: 'backend.php',
    get_warehouse_kpis: 'backend.php',
    get_warehouse_supply_by_product: 'backend.php',
    get_warehouse_supply_by_location: 'backend.php',
    get_warehouse_stockout_items: 'backend.php',
    get_warehouse_product_kpis: 'backend.php',
    get_top_products_by_quantity: 'backend.php',
    get_stock_distribution_by_category: 'backend.php',
    get_fast_moving_items_trend: 'backend.php',
    get_critical_stock_alerts: 'backend.php',
    get_inventory_by_branch_category: 'backend.php',
    get_reports_data: 'backend.php',
    get_inventory_summary_report: 'backend.php',
    get_low_stock_report: 'backend.php',
    get_expiry_report: 'backend.php',
    get_movement_history_report: 'backend.php',
    get_expiring_products: 'backend.php',
    get_supply_by_product: 'backend.php',
    
    // Dashboard Reports
    get_dashboard_sales: 'dashboard_sales_api.php',
    get_dashboard_returns: 'dashboard_return_api.php',
    get_dashboard_transfers: 'dashboard_transfer_api.php',
    get_combined_reports: 'combined_reports_api.php',

    // ============= STOCK ADJUSTMENTS =============
    get_stock_adjustments: 'batch_stock_adjustment_api.php',
    create_stock_adjustment: 'batch_stock_adjustment_api.php',
    update_stock_adjustment: 'batch_stock_adjustment_api.php',
    delete_stock_adjustment: 'batch_stock_adjustment_api.php',
    get_stock_adjustment_stats: 'batch_stock_adjustment_api.php',
    
    // Stock Summary
    get_stock_summary: 'stock_summary_api.php',
    get_stock_summary_by_location: 'stock_summary_api.php',

    // ============= ARCHIVE MANAGEMENT =============
    get_archived_products: 'backend.php',
    get_archived_items: 'backend.php',
    restore_archived_item: 'backend.php',
    delete_archived_item: 'backend.php',

    // ============= PURCHASE ORDERS =============
    create_purchase_order: 'create_purchase_order_api.php',
    get_purchase_orders: 'purchase_order_api.php',
    update_purchase_order: 'purchase_order_api.php',
    delete_purchase_order: 'purchase_order_api.php',

    // ============= ADMIN/DEBUG =============
    test_connection: 'backend.php',
    check_table_structure: 'backend.php',
    debug_brands_suppliers: 'backend.php',
    clear_brands: 'backend.php',
    diagnose_warehouse_data: 'backend.php',
    emergency_restore_warehouse: 'backend.php',
    test_database_connection: 'backend.php',
    test_action: 'backend.php',
  };
  return actionMap[action] || 'backend.php'; // fallback to backend.php for all unmapped actions
};

// Additional utility functions for common patterns
export const handleApiError = (error, defaultMessage = 'An error occurred') => {
  
  return {
    success: false,
    message: error.message || defaultMessage,
    error: error
  };
};

export const validateApiResponse = (response) => {
  if (!response) {
    throw new Error('No response received');
  }
  
  if (!response.success) {
    throw new Error(response.message || 'API call failed');
  }
  
  return response;
};

// Toast notification helper (optional)
export const showApiNotification = (response, successMessage = 'Operation successful') => {
  if (typeof window !== 'undefined' && window.toast) {
    if (response.success) {
      window.toast.success(successMessage);
    } else {
      window.toast.error(response.message || 'Operation failed');
    }
  }
};
