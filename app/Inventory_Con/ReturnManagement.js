'use client';

import { useState, useEffect } from 'react';
import { toast } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import { useTheme } from './ThemeContext';
import { useNotification } from './NotificationContext';

export default function ReturnManagement() {
  const { isDarkMode, theme } = useTheme();
  const { markNotificationAsViewed, getTotalNotifications } = useNotification();
  const [activeTab, setActiveTab] = useState('pending');
  const [pendingReturns, setPendingReturns] = useState([]);
  const [returnHistory, setReturnHistory] = useState([]);
  const [loading, setLoading] = useState(false);
  const [selectedReturn, setSelectedReturn] = useState(null);
  const [showDetailsModal, setShowDetailsModal] = useState(false);
  const [approvalNotes, setApprovalNotes] = useState('');
  const [rejectionReason, setRejectionReason] = useState('');
  const [showApprovalModal, setShowApprovalModal] = useState(false);
  const [showRejectionModal, setShowRejectionModal] = useState(false);
  const [filters, setFilters] = useState({
    status: 'all',
    location: 'all',
    dateFrom: '',
    dateTo: ''
  });
  const [refreshInterval, setRefreshInterval] = useState(null);
  const [lastRefresh, setLastRefresh] = useState(new Date());

  useEffect(() => {
    if (activeTab === 'pending') {
      loadPendingReturns();
    } else {
      loadReturnHistory();
    }
  }, [activeTab]);

  // Clear return notifications when component is viewed
  useEffect(() => {
    markNotificationAsViewed('returns');
  }, []); // Empty dependency array - only run once when component mounts

  // Set up real-time refresh every 30 seconds
  useEffect(() => {
    const interval = setInterval(() => {
      if (activeTab === 'pending') {
        loadPendingReturns();
      } else {
        loadReturnHistory();
      }
    }, 30000); // Refresh every 30 seconds

    setRefreshInterval(interval);

    // Cleanup interval on component unmount
    return () => {
      if (interval) {
        clearInterval(interval);
      }
    };
  }, [activeTab]); // Re-setup interval when activeTab changes

  const loadPendingReturns = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/caps2e2/Api'}/pos_return_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'get_pending_returns',
          limit: 100
        })
      });

      const data = await response.json();
      if (data.success) {
        setPendingReturns(data.data);
        setLastRefresh(new Date());
      } else {
        console.error('Failed to load pending returns:', data.message);
      }
    } catch (error) {
      console.error('Error loading pending returns:', error);
    } finally {
      setLoading(false);
    }
  };

  const loadReturnHistory = async () => {
    setLoading(true);
    try {
      console.log('Loading return history...');
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/caps2e2/Api'}/pos_return_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'get_all_returns',
          limit: 100
        })
      });

      console.log('Response status:', response.status);
      const data = await response.json();
      console.log('Return history API response:', data);
      
      if (data.success) {
        // Show all returns in history tab (including completed, approved, rejected)
        console.log('Setting return history:', data.data.length, 'returns');
        setReturnHistory(data.data);
        setLastRefresh(new Date());
      } else {
        console.error('Failed to load return history:', data.message);
      }
    } catch (error) {
      console.error('Error loading return history:', error);
    } finally {
      setLoading(false);
    }
  };

  const getReturnDetails = async (returnId) => {
    try {
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/caps2e2/Api'}/pos_return_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'get_return_details',
          return_id: returnId
        })
      });

      const data = await response.json();
      if (data.success) {
        return data.data;
      }
      return [];
    } catch (error) {
      console.error('Error loading return details:', error);
      return [];
    }
  };

  const showReturnDetails = async (returnItem) => {
    const details = await getReturnDetails(returnItem.return_id);
    setSelectedReturn({ ...returnItem, items: details });
    setShowDetailsModal(true);
  };

  const approveReturn = async () => {
    if (!selectedReturn) return;

    try {
      const userData = JSON.parse(sessionStorage.getItem('user_data') || '{}');
      
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/caps2e2/Api'}/pos_return_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'approve_return',
          return_id: selectedReturn.return_id,
          approved_by: userData.user_id || 1,
          approved_by_username: userData.username || 'Admin',
          notes: approvalNotes
        })
      });

      const data = await response.json();
      if (data.success) {
        const transferMessage = data.transfer_details_updated ? 'Transfer details have been updated with returned quantities.' : '';
        toast.success(
          <div>
            <div className="font-bold text-lg">Return Approved Successfully!</div>
            <div className="mt-2">
              <div>Stock has been restored to <strong>{data.location_name}</strong></div>
              {transferMessage && <div className="mt-1">{transferMessage}</div>}
            </div>
            <div className="mt-3 text-sm">
              <div><strong>Restored Items:</strong> {data.restored_items}</div>
              <div><strong>Total Quantity:</strong> {data.total_quantity_restored} units</div>
            </div>
          </div>,
          { autoClose: 8000 }
        );
        setShowApprovalModal(false);
        setShowDetailsModal(false);
        setApprovalNotes('');
        loadPendingReturns();
        
        // Clear return notifications after approval
        markNotificationAsViewed('returns');
        
        // Trigger inventory refresh for the specific location
        if (data.location_name) {
          // Dispatch custom event to notify inventory components
          window.dispatchEvent(new CustomEvent('inventoryRefresh', {
            detail: {
              location: data.location_name,
              action: 'return_approved',
              message: `Stock restored: ${data.total_quantity_restored} units`,
              transferDetailsUpdated: data.transfer_details_updated || false
            }
          }));
        }
      } else {
        toast.error(`Failed to approve return: ${data.message}`);
      }
    } catch (error) {
      console.error('Error approving return:', error);
      toast.error('Error approving return. Please try again.');
    }
  };

  const rejectReturn = async () => {
    if (!selectedReturn) return;

    try {
      const userData = JSON.parse(sessionStorage.getItem('user_data') || '{}');
      
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/caps2e2/Api'}/pos_return_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'reject_return',
          return_id: selectedReturn.return_id,
          rejected_by: userData.user_id || 1,
          rejected_by_username: userData.username || 'Admin',
          rejection_reason: rejectionReason
        })
      });

      const data = await response.json();
      if (data.success) {
        toast.success('Return rejected successfully.');
        setShowRejectionModal(false);
        setShowDetailsModal(false);
        setRejectionReason('');
        loadPendingReturns();
        
        // Clear return notifications after rejection
        markNotificationAsViewed('returns');
      } else {
        toast.error(`Failed to reject return: ${data.message}`);
      }
    } catch (error) {
      console.error('Error rejecting return:', error);
      toast.error('Error rejecting return. Please try again.');
    }
  };

  const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP'
    }).format(amount);
  };

  const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString('en-PH');
  };

  const handleManualRefresh = () => {
    if (activeTab === 'pending') {
      loadPendingReturns();
    } else {
      loadReturnHistory();
    }
  };

  // Theme-based styles
  const themeStyles = {
    container: {
      backgroundColor: isDarkMode ? 'var(--inventory-bg-primary)' : 'var(--inventory-bg-primary)',
      color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)'
    },
    card: {
      backgroundColor: isDarkMode ? 'var(--inventory-bg-card)' : 'var(--inventory-bg-card)',
      borderColor: isDarkMode ? 'var(--inventory-border)' : 'var(--inventory-border)',
      boxShadow: isDarkMode ? 'var(--inventory-shadow)' : 'var(--inventory-shadow)'
    },
    text: {
      primary: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)',
      secondary: isDarkMode ? 'var(--inventory-text-secondary)' : 'var(--inventory-text-secondary)',
      muted: isDarkMode ? 'var(--inventory-text-muted)' : 'var(--inventory-text-muted)'
    },
    border: {
      color: isDarkMode ? 'var(--inventory-border)' : 'var(--inventory-border)',
      light: isDarkMode ? 'var(--inventory-border-light)' : 'var(--inventory-border-light)'
    },
    input: {
      backgroundColor: isDarkMode ? 'var(--inventory-bg-card)' : 'var(--inventory-bg-card)',
      borderColor: isDarkMode ? 'var(--inventory-border)' : 'var(--inventory-border)',
      color: isDarkMode ? 'var(--inventory-text-primary)' : 'var(--inventory-text-primary)',
      placeholderColor: isDarkMode ? 'var(--inventory-text-muted)' : 'var(--inventory-text-muted)'
    }
  };

  const getStatusBadge = (status) => {
    const statusColors = {
      pending: isDarkMode ? 'bg-yellow-900/30 text-yellow-300 border border-yellow-700' : 'bg-yellow-100 text-yellow-800',
      approved: isDarkMode ? 'bg-green-900/30 text-green-300 border border-green-700' : 'bg-green-100 text-green-800',
      rejected: isDarkMode ? 'bg-red-900/30 text-red-300 border border-red-700' : 'bg-red-100 text-red-800',
      completed: isDarkMode ? 'bg-gray-900/30 text-gray-300 border border-gray-700' : 'bg-gray-100 text-gray-800',
      cancelled: isDarkMode ? 'bg-gray-900/30 text-gray-300 border border-gray-700' : 'bg-gray-100 text-gray-800'
    };
    
    return (
      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${statusColors[status] || (isDarkMode ? 'bg-gray-900/30 text-gray-300 border border-gray-700' : 'bg-gray-100 text-gray-800')}`}>
        {status.toUpperCase()}
      </span>
    );
  };

  const filteredReturns = () => {
    const returns = activeTab === 'pending' ? pendingReturns : returnHistory;
    console.log('Filtering returns:', {
      activeTab,
      returnsCount: returns.length,
      filters,
      returns: returns
    });
    
    const filtered = returns.filter(returnItem => {
      if (filters.status !== 'all' && returnItem.status !== filters.status) return false;
      if (filters.location !== 'all' && returnItem.location_name !== filters.location) return false;
      if (filters.dateFrom && new Date(returnItem.created_at) < new Date(filters.dateFrom)) return false;
      if (filters.dateTo && new Date(returnItem.created_at) > new Date(filters.dateTo)) return false;
      return true;
    });
    
    console.log('Filtered results:', filtered.length);
    return filtered;
  };

  return (
    <div className="p-6 space-y-6 min-h-screen" style={themeStyles.container}>
      <div className="flex justify-between items-center mb-6">
        <div className="flex items-center gap-3">
          <h1 className="text-2xl font-bold" style={{ color: themeStyles.text.primary }}>Return Management</h1>
          {getTotalNotifications('returns') > 0 && (
            <span className="bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">
              {getTotalNotifications('returns')} New
            </span>
          )}
          <div className="flex items-center gap-2 text-sm" style={{ color: themeStyles.text.muted }}>
            <div className="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
            <span>Auto-refresh every 30s</span>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <div className="text-sm" style={{ color: themeStyles.text.muted }}>
            Last updated: {lastRefresh.toLocaleTimeString()}
          </div>
          <button
            onClick={handleManualRefresh}
            disabled={loading}
            className="px-3 py-1 text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            style={{ 
              color: themeStyles.text.secondary,
              ':hover': { color: themeStyles.text.primary }
            }}
            title="Refresh now"
          >
            {loading ? 'Refreshing...' : '↻ Refresh'}
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div className="mb-6">
        <div className="border-b" style={{ borderColor: themeStyles.border.color }}>
          <nav className="-mb-px flex space-x-8">
            <button
              onClick={() => setActiveTab('pending')}
              className="py-2 px-1 border-b-2 font-medium text-sm transition-colors"
              style={activeTab === 'pending' ? 
                { borderBottomColor: theme.colors.accent, color: theme.colors.accent } : 
                { borderBottomColor: 'transparent', color: themeStyles.text.secondary }
              }
              onMouseEnter={(e) => {
                if (activeTab !== 'pending') {
                  e.target.style.color = themeStyles.text.primary;
                }
              }}
              onMouseLeave={(e) => {
                if (activeTab !== 'pending') {
                  e.target.style.color = themeStyles.text.secondary;
                }
              }}
            >
              Pending Returns ({pendingReturns.length})
            </button>
            <button
              onClick={() => setActiveTab('history')}
              className="py-2 px-1 border-b-2 font-medium text-sm transition-colors"
              style={activeTab === 'history' ? 
                { borderBottomColor: theme.colors.accent, color: theme.colors.accent } : 
                { borderBottomColor: 'transparent', color: themeStyles.text.secondary }
              }
              onMouseEnter={(e) => {
                if (activeTab !== 'history') {
                  e.target.style.color = themeStyles.text.primary;
                }
              }}
              onMouseLeave={(e) => {
                if (activeTab !== 'history') {
                  e.target.style.color = themeStyles.text.secondary;
                }
              }}
            >
              Return History ({returnHistory.length})
            </button>
          </nav>
        </div>
      </div>

      {/* Filters */}
      <div className="p-4 rounded-lg shadow mb-6" style={themeStyles.card}>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: themeStyles.text.primary }}>Status</label>
            <select
              value={filters.status}
              onChange={(e) => setFilters({...filters, status: e.target.value})}
              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 transition-colors"
              style={{
                ...themeStyles.input,
                focusRingColor: theme.colors.accent
              }}
            >
              <option value="all">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: themeStyles.text.primary }}>Location</label>
            <select
              value={filters.location}
              onChange={(e) => setFilters({...filters, location: e.target.value})}
              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 transition-colors"
              style={{
                ...themeStyles.input,
                focusRingColor: theme.colors.accent
              }}
            >
              <option value="all">All Locations</option>
              <option value="Convenience Store">Convenience Store</option>
              <option value="Pharmacy">Pharmacy</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: themeStyles.text.primary }}>Date From</label>
            <input
              type="date"
              value={filters.dateFrom}
              onChange={(e) => setFilters({...filters, dateFrom: e.target.value})}
              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 transition-colors"
              style={{
                ...themeStyles.input,
                focusRingColor: theme.colors.accent
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: themeStyles.text.primary }}>Date To</label>
            <input
              type="date"
              value={filters.dateTo}
              onChange={(e) => setFilters({...filters, dateTo: e.target.value})}
              className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 transition-colors"
              style={{
                ...themeStyles.input,
                focusRingColor: theme.colors.accent
              }}
            />
          </div>
        </div>
      </div>

      {/* Returns Table */}
      {loading ? (
        <div className="text-center py-8">
          <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderBottomColor: theme.colors.accent }}></div>
          <p className="mt-2" style={{ color: themeStyles.text.secondary }}>Loading returns...</p>
        </div>
      ) : (
        <div className="rounded-lg shadow overflow-hidden" style={themeStyles.card}>
          {filteredReturns().length === 0 ? (
            <div className="text-center py-8" style={{ color: themeStyles.text.muted }}>
              <p className="text-lg">No returns found</p>
              <p className="text-sm">
                {activeTab === 'pending' 
                  ? 'All returns have been processed' 
                  : 'No return history available'
                }
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y" style={{ color: themeStyles.text.primary }}>
                <thead className="border-b sticky top-0 z-10" style={{ backgroundColor: isDarkMode ? '#374151' : '#f8fafc', borderColor: themeStyles.border.color }}>
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Return ID
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Original Transaction
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Reason
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Location
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Amount
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Submitted By
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Date
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider" style={{ color: themeStyles.text.primary }}>
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y" style={{ backgroundColor: themeStyles.card.backgroundColor, borderColor: themeStyles.border.color }}>
                  {filteredReturns().map((returnItem) => (
                    <tr key={returnItem.return_id} className="hover:opacity-80 transition-colors" style={{ backgroundColor: themeStyles.card.backgroundColor }}>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium" style={{ color: themeStyles.text.primary }}>
                        {returnItem.return_id}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm" style={{ color: themeStyles.text.secondary }}>
                        {returnItem.original_transaction_id}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm" style={{ color: themeStyles.text.secondary }}>
                        {returnItem.reason}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm" style={{ color: themeStyles.text.secondary }}>
                        {returnItem.location_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium" style={{ color: themeStyles.text.primary }}>
                        {formatCurrency(returnItem.total_refund)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {getStatusBadge(returnItem.status)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm" style={{ color: themeStyles.text.secondary }}>
                        {returnItem.username}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm" style={{ color: themeStyles.text.secondary }}>
                        {formatDate(returnItem.created_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button
                          onClick={() => showReturnDetails(returnItem)}
                          className="hover:underline transition-colors"
                          style={{ color: theme.colors.accent }}
                        >
                          View Details
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

      {/* Return Details Modal */}
      {showDetailsModal && selectedReturn && (
        <div className="fixed inset-0 overflow-y-auto h-full w-full z-50">
          <div className="relative top-20 mx-auto p-5 border-2 shadow-2xl rounded-lg ring-4 w-11/12 md:w-3/4 lg:w-1/2" 
               style={{ 
                 borderColor: theme.colors.accent, 
                 backgroundColor: themeStyles.card.backgroundColor,
                 ringColor: isDarkMode ? 'rgba(59, 130, 246, 0.3)' : 'rgba(59, 130, 246, 0.2)'
               }}>
            <div className="mt-3">
              <div className="flex justify-between items-center mb-4">
                <h3 className="text-lg font-medium" style={{ color: themeStyles.text.primary }}>
                  Return Details - {selectedReturn.return_id}
                </h3>
                <button
                  onClick={() => setShowDetailsModal(false)}
                  className="transition-colors"
                  style={{ color: themeStyles.text.muted }}
                  onMouseEnter={(e) => e.target.style.color = themeStyles.text.secondary}
                  onMouseLeave={(e) => e.target.style.color = themeStyles.text.muted}
                >
                  <span className="sr-only">Close</span>
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Original Transaction</label>
                    <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{selectedReturn.original_transaction_id}</p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Location</label>
                    <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{selectedReturn.location_name}</p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Reason</label>
                    <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{selectedReturn.reason}</p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Status</label>
                    <div className="mt-1">{getStatusBadge(selectedReturn.status)}</div>
                  </div>
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Total Amount</label>
                    <p className="mt-1 text-sm font-medium" style={{ color: themeStyles.text.primary }}>{formatCurrency(selectedReturn.total_refund)}</p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Submitted By</label>
                    <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{selectedReturn.username}</p>
                  </div>
                </div>

                {selectedReturn.approved_by_username && (
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Approved By</label>
                      <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{selectedReturn.approved_by_username}</p>
                    </div>
                    <div>
                      <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Approved At</label>
                      <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{formatDate(selectedReturn.approved_at)}</p>
                    </div>
                  </div>
                )}

                {selectedReturn.rejection_reason && (
                  <div>
                    <label className="block text-sm font-medium" style={{ color: themeStyles.text.primary }}>Rejection Reason</label>
                    <p className="mt-1 text-sm" style={{ color: themeStyles.text.secondary }}>{selectedReturn.rejection_reason}</p>
                  </div>
                )}

                <div>
                  <label className="block text-sm font-medium mb-2" style={{ color: themeStyles.text.primary }}>Return Items</label>
                  <div className="overflow-x-auto">
                    <table className="min-w-full divide-y" style={{ borderColor: themeStyles.border.color }}>
                      <thead style={{ backgroundColor: isDarkMode ? '#374151' : '#f8fafc' }}>
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase" style={{ color: themeStyles.text.primary }}>Product</th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase" style={{ color: themeStyles.text.primary }}>Quantity</th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase" style={{ color: themeStyles.text.primary }}>Price</th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase" style={{ color: themeStyles.text.primary }}>Total</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y" style={{ backgroundColor: themeStyles.card.backgroundColor, borderColor: themeStyles.border.color }}>
                        {selectedReturn.items.map((item, index) => (
                          <tr key={index}>
                            <td className="px-4 py-2 text-sm" style={{ color: themeStyles.text.primary }}>{item.product_name}</td>
                            <td className="px-4 py-2 text-sm" style={{ color: themeStyles.text.primary }}>{item.quantity}</td>
                            <td className="px-4 py-2 text-sm" style={{ color: themeStyles.text.primary }}>{formatCurrency(item.price)}</td>
                            <td className="px-4 py-2 text-sm font-medium" style={{ color: themeStyles.text.primary }}>{formatCurrency(item.total)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {selectedReturn.status === 'pending' && (
                  <div className="flex justify-end space-x-3 pt-4">
                    <button
                      onClick={() => {
                        setShowApprovalModal(true);
                        setApprovalNotes('');
                      }}
                      className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                    >
                      Approve Return
                    </button>
                    <button
                      onClick={() => {
                        setShowRejectionModal(true);
                        setRejectionReason('');
                      }}
                      className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                    >
                      Reject Return
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Approval Modal */}
      {showApprovalModal && (
        <div className="fixed inset-0 overflow-y-auto h-full w-full z-50">
          <div className="relative top-20 mx-auto p-5 border-2 w-96 shadow-2xl rounded-lg ring-4" 
               style={{ 
                 borderColor: '#10b981', 
                 backgroundColor: themeStyles.card.backgroundColor,
                 ringColor: isDarkMode ? 'rgba(16, 185, 129, 0.3)' : 'rgba(16, 185, 129, 0.2)'
               }}>
            <div className="mt-3">
              <h3 className="text-lg font-medium mb-4" style={{ color: themeStyles.text.primary }}>Approve Return</h3>
              <div className="mb-4">
                <label className="block text-sm font-medium mb-2" style={{ color: themeStyles.text.primary }}>Approval Notes (Optional)</label>
                <textarea
                  value={approvalNotes}
                  onChange={(e) => setApprovalNotes(e.target.value)}
                  className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 transition-colors"
                  style={{
                    ...themeStyles.input,
                    focusRingColor: theme.colors.accent
                  }}
                  rows={3}
                  placeholder="Add any notes about this approval..."
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  onClick={() => setShowApprovalModal(false)}
                  className="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={approveReturn}
                  className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                >
                  Approve
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Rejection Modal */}
      {showRejectionModal && (
        <div className="fixed inset-0 overflow-y-auto h-full w-full z-50">
          <div className="relative top-20 mx-auto p-5 border-2 w-96 shadow-2xl rounded-lg ring-4" 
               style={{ 
                 borderColor: '#ef4444', 
                 backgroundColor: themeStyles.card.backgroundColor,
                 ringColor: isDarkMode ? 'rgba(239, 68, 68, 0.3)' : 'rgba(239, 68, 68, 0.2)'
               }}>
            <div className="mt-3">
              <h3 className="text-lg font-medium mb-4" style={{ color: themeStyles.text.primary }}>Reject Return</h3>
              <div className="mb-4">
                <label className="block text-sm font-medium mb-2" style={{ color: themeStyles.text.primary }}>Rejection Reason *</label>
                <textarea
                  value={rejectionReason}
                  onChange={(e) => setRejectionReason(e.target.value)}
                  className="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 transition-colors"
                  style={{
                    ...themeStyles.input,
                    focusRingColor: theme.colors.accent
                  }}
                  rows={3}
                  placeholder="Please provide a reason for rejecting this return..."
                  required
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  onClick={() => setShowRejectionModal(false)}
                  className="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={rejectReturn}
                  disabled={!rejectionReason.trim()}
                  className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
                >
                  Reject
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
