"use client";
import React, { useState, useEffect } from "react";
import axios from "axios";
import jsPDF from 'jspdf';
import html2canvas from 'html2canvas';
import { useTheme } from './ThemeContext';
import { useNotification } from './NotificationContext';

// Use environment-based API base URL
const API_BASE_URL = `${process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/caps2e2/Api'}/backend.php`;

function Reports() {
  const { theme } = useTheme();
  const { updateReportsNotifications, updateSystemUpdates, clearNotifications, clearSystemUpdates, markNotificationAsViewed } = useNotification();
  const [reports, setReports] = useState([]);
  const [reportsLoading, setReportsLoading] = useState(false);
  const [reportsPage, setReportsPage] = useState(1);
  const [selectedReportType, setSelectedReportType] = useState('all');
  const [reportData, setReportData] = useState([]);
  const [reportDataLoading, setReportDataLoading] = useState(false);
  const [reportError, setReportError] = useState(null);
  const [dateRange, setDateRange] = useState({
    startDate: new Date().toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0]
  });
  const [showGenerateModal, setShowGenerateModal] = useState(false);
  const [selectedGenerateReportType, setSelectedGenerateReportType] = useState('');
  const [generateDateRange, setGenerateDateRange] = useState({
    startDate: new Date().toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0]
  });
  const [showCombineModal, setShowCombineModal] = useState(false);
  const [selectedCombineReportType, setSelectedCombineReportType] = useState('');
  const [selectedReportTypes, setSelectedReportTypes] = useState(['all']);
  const [combineDateRange, setCombineDateRange] = useState({
    startDate: new Date().toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0]
  });
  const itemsPerPage = 12;

  const reportTypes = [
    { id: 'all', name: 'All Reports', icon: '📊' },
    { id: 'stock_in', name: 'Stock In Report', icon: '📦' },
    { id: 'stock_out', name: 'Stock Out Report', icon: '📤' },
    { id: 'sales', name: 'Sales Report', icon: '💰' },
    { id: 'inventory_balance', name: 'Inventory Balance Report', icon: '📋' },
    { id: 'supplier', name: 'Supplier Report', icon: '🏢' },
    { id: 'cashier_performance', name: 'Cashier Performance Report', icon: '👤' },
    { id: 'login_logs', name: 'Login Logs Report', icon: '🔐' }
  ];

  const fetchReports = async () => {
    try {
      setReportsLoading(true);
      console.log('📊 Fetching reports from:', API_BASE_URL);
      
      const res = await axios.post(API_BASE_URL, { action: 'get_reports_data' });
      console.log('📊 Reports API Response:', res.data);
      
      if (res.data?.success && Array.isArray(res.data.reports)) {
        console.log('✅ Reports fetched successfully:', res.data.reports.length, 'reports');
        setReports(res.data.reports);
        setReportsPage(1);
        
        // Update notifications based on reports
        const hasNewReports = res.data.reports.some(report => {
          const reportDate = new Date(report.date);
          const now = new Date();
          const diffHours = (now - reportDate) / (1000 * 60 * 60);
          return diffHours < 24; // Reports from last 24 hours
        });

        // Update sub-item notifications
        const subItemUpdates = {};
        reportTypes.forEach(type => {
          if (type.id !== 'all') {
            const typeReports = res.data.reports.filter(report => report.type === type.name);
            subItemUpdates[type.name] = {
              hasUpdates: typeReports.length > 0,
              count: typeReports.length
            };
          }
        });

        updateReportsNotifications(hasNewReports, res.data.reports.length, subItemUpdates);
      } else {
        console.warn('⚠️ No reports data or invalid response structure:', res.data);
        setReports([]);
      }
    } catch (error) {
      console.error('❌ Error fetching reports:', error);
      console.error('Error details:', {
        message: error.message,
        response: error.response?.data,
        status: error.response?.status
      });
      setReports([]);
    } finally {
      setReportsLoading(false);
    }
  };

  const fetchReportData = async (reportType, retryCount = 0) => {
    try {
      setReportDataLoading(true);
      setReportError(null);
      
      const requestData = { 
        action: 'get_report_data',
        report_type: reportType,
        start_date: dateRange.startDate,
        end_date: dateRange.endDate
      };
      
      let res;
      
      // Try axios first
      try {
        res = await axios.post(API_BASE_URL, requestData, {
          timeout: 10000,
          headers: {
            'Content-Type': 'application/json',
          }
        });
      } catch (axiosError) {
        console.warn('Axios failed, trying fetch:', axiosError.message);
        
        // Fallback to fetch
        const response = await fetch(API_BASE_URL, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(requestData)
        });
        
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        res = { data };
      }
      
      if (res.data?.success) {
        setReportData(res.data.data || []);
        setReportError(null);
      } else {
        setReportData([]);
        setReportError(res.data?.message || 'Failed to fetch report data');
        console.warn('Report data fetch failed:', res.data?.message);
      }
    } catch (error) {
      console.error('Error fetching report data:', error);
      setReportData([]);
      
      // Retry logic for network errors
      if (retryCount < 2 && (error.message === 'Network Error' || error.message.includes('fetch'))) {
        console.log(`Retrying... attempt ${retryCount + 1}`);
        setTimeout(() => {
          fetchReportData(reportType, retryCount + 1);
        }, 2000);
        return;
      }
      
      // Set user-friendly error message
      if (error.message === 'Network Error' || error.message.includes('fetch')) {
        setReportError('Network Error: Please check if XAMPP services (Apache & MySQL) are running');
      } else if (error.message.includes('timeout')) {
        setReportError('Request timeout: Server may be slow, please try again');
      } else {
        setReportError(`Failed to load report data: ${error.message}`);
      }
    } finally {
      setReportDataLoading(false);
    }
  };

  const generateReport = async (reportType) => {
    try {
      setReportDataLoading(true);
      const res = await axios.post(API_BASE_URL, {
        action: 'generate_report',
        report_type: reportType,
        generated_by: 'Admin',
        parameters: {
          start_date: dateRange.startDate,
          end_date: dateRange.endDate
        }
      });
      
      if (res.data?.success) {
        // Refresh reports list
        fetchReports();
        // Fetch the generated report data
        fetchReportData(reportType);
      }
    } catch (error) {
      console.error('Error generating report:', error);
    } finally {
      setReportDataLoading(false);
    }
  };

  const generateIndividualReport = async (reportType) => {
    try {
      setReportDataLoading(true);
      const res = await axios.post(API_BASE_URL, {
        action: 'generate_report',
        report_type: reportType,
        generated_by: 'Admin',
        parameters: {
          start_date: generateDateRange.startDate,
          end_date: generateDateRange.endDate
        }
      });
      
      if (res.data?.success) {
        // Refresh reports list
        fetchReports();
        // Close modal
        setShowGenerateModal(false);
        // Show success notification
        console.log(`${reportTypes.find(t => t.id === reportType)?.name} generated successfully!`);
      }
    } catch (error) {
      console.error('Error generating individual report:', error);
    } finally {
      setReportDataLoading(false);
    }
  };

  const openGenerateModal = (reportType) => {
    setSelectedGenerateReportType(reportType);
    setGenerateDateRange({
      startDate: new Date().toISOString().split('T')[0],
      endDate: new Date().toISOString().split('T')[0]
    });
    setShowGenerateModal(true);
  };

  const combineIndividualReports = async () => {
    try {
      setReportDataLoading(true);
      
      // Convert selected report types to API format
      let reportTypesToCombine = selectedReportTypes;
      if (selectedReportTypes.includes('all')) {
        reportTypesToCombine = ['stock_in', 'stock_out', 'sales', 'cashier_performance', 'inventory_balance'];
      }
      
      // Generate PDF directly
      await generateCombinedPDF(reportTypesToCombine);
      
      // Close modal
      setShowCombineModal(false);
      
    } catch (error) {
      console.error('Error combining reports:', error);
    } finally {
      setReportDataLoading(false);
    }
  };

  const generateCombinedPDF = async (reportTypes) => {
    try {
      console.log('📄 Generating combined PDF for:', reportTypes);
      
      // Fetch data for all selected report types
      const allReportsData = {};
      for (const reportType of reportTypes) {
        try {
          console.log(`📊 Fetching data for ${reportType}...`);
          const res = await axios.post(API_BASE_URL, {
            action: 'get_report_data',
            report_type: reportType,
            start_date: combineDateRange.startDate,
            end_date: combineDateRange.endDate
          });
          
          if (res.data?.success) {
            allReportsData[reportType] = res.data.data || [];
            console.log(`✅ Fetched ${allReportsData[reportType].length} records for ${reportType}`);
          } else {
            allReportsData[reportType] = [];
            console.warn(`⚠️ No data for ${reportType}`);
          }
        } catch (error) {
          console.error(`❌ Error fetching ${reportType}:`, error);
          allReportsData[reportType] = [];
        }
      }
      
      // Create a temporary div for PDF generation
      const tempDiv = document.createElement('div');
      tempDiv.style.position = 'absolute';
      tempDiv.style.left = '-9999px';
      tempDiv.style.top = '-9999px';
      tempDiv.style.width = '210mm'; // A4 width
      tempDiv.style.backgroundColor = 'white';
      tempDiv.style.padding = '20px';
      tempDiv.style.fontFamily = 'Arial, sans-serif';
      tempDiv.style.fontSize = '10px';
      tempDiv.style.lineHeight = '1.3';
      
      // Get report type definitions (from the constant at top of component)
      const reportTypesList = [
        { id: 'stock_in', name: 'Stock In Report', icon: '📦' },
        { id: 'stock_out', name: 'Stock Out Report', icon: '📤' },
        { id: 'sales', name: 'Sales Report', icon: '💰' },
        { id: 'inventory_balance', name: 'Inventory Balance Report', icon: '📋' },
        { id: 'supplier', name: 'Supplier Report', icon: '🏢' },
        { id: 'cashier_performance', name: 'Cashier Performance Report', icon: '👤' },
        { id: 'login_logs', name: 'Login Logs Report', icon: '🔐' }
      ];
      
      // Create report names list
      const reportNames = reportTypes.map(typeId => {
        const reportTypeInfo = reportTypesList.find(t => t.id === typeId);
        return reportTypeInfo?.name || typeId.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
      }).join(', ');
      
      // Build HTML content with header
      let htmlContent = `
        <div style="text-align: center; margin-bottom: 20px; padding: 15px; background: #f8fafc; border: 2px solid #000000;">
          <div style="font-size: 20px; font-weight: bold; color: #000000; margin-bottom: 3px;">ENGUIO PHARMACY SYSTEM</div>
          <div style="font-size: 12px; color: #000000;">Combined Reports</div>
        </div>
        
        <div style="margin-bottom: 15px; padding: 10px; background: #f1f5f9; border-left: 4px solid #000000;">
          <div style="font-size: 11px; font-weight: bold; color: #000000; margin-bottom: 8px;">Report Information</div>
          <div style="font-size: 9px; color: #000000; line-height: 1.5;">
            <strong>Date Range:</strong> ${combineDateRange.startDate} to ${combineDateRange.endDate}<br>
            <strong>Generated:</strong> ${new Date().toLocaleDateString('en-PH')} at ${new Date().toLocaleTimeString('en-PH')}<br>
            <strong>Generated by:</strong> Admin<br>
            <strong>Report Types:</strong> ${reportNames}
          </div>
        </div>
      `;
      
      // Add each report's data
      for (const reportType of reportTypes) {
        const data = allReportsData[reportType] || [];
        const reportInfo = reportTypesList.find(t => t.id === reportType);
        const reportName = reportInfo?.name || reportType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        
        htmlContent += `
          <div style="page-break-before: auto; margin-top: 20px; margin-bottom: 15px;">
            <div style="font-size: 13px; font-weight: bold; color: #000000; padding: 8px; background: #e2e8f0; border-left: 4px solid #000000; margin-bottom: 10px;">
              ${reportInfo?.icon || '📊'} ${reportName}
            </div>
        `;
        
        if (data.length === 0) {
          htmlContent += `
            <div style="padding: 15px; text-align: center; color: #64748b; font-size: 9px;">
              No data available for this report type in the selected date range.
            </div>
          `;
        } else {
          // Get columns for this report type
          const columns = getReportColumns(reportType);
          
          // Create table
          htmlContent += `
            <table style="width: 100%; border-collapse: collapse; font-size: 8px; margin-bottom: 10px;">
              <thead>
                <tr style="background: #cbd5e1;">
                  ${columns.map(col => `<th style="border: 1px solid #000; padding: 4px; text-align: left; font-weight: bold;">${col}</th>`).join('')}
                </tr>
              </thead>
              <tbody>
          `;
          
          // Add rows (limit to 50 per report for PDF size)
          const limitedData = data.slice(0, 50);
          limitedData.forEach((row, index) => {
            htmlContent += `<tr style="background: ${index % 2 === 0 ? '#ffffff' : '#f8fafc'};">`;
            columns.forEach(column => {
              const columnKey = column.toLowerCase().replace(/\s+/g, '_');
              let cellValue = row[columnKey] || 'N/A';
              
              // Format values
              if (columnKey.includes('total_value') || columnKey.includes('total_amount') || columnKey.includes('unit_price') || columnKey.includes('average_transaction')) {
                cellValue = `₱${parseFloat(cellValue || 0).toFixed(2)}`;
              } else if (columnKey === 'date' && cellValue !== 'N/A') {
                cellValue = new Date(cellValue).toLocaleDateString('en-PH');
              } else if (columnKey === 'time' && cellValue !== 'N/A') {
                try {
                  cellValue = new Date(`2000-01-01T${cellValue}`).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
                } catch (e) {
                  // Keep original if parsing fails
                }
              }
              
              htmlContent += `<td style="border: 1px solid #ddd; padding: 3px; font-size: 7px;">${cellValue}</td>`;
            });
            htmlContent += `</tr>`;
          });
          
          htmlContent += `
              </tbody>
            </table>
          `;
          
          if (data.length > 50) {
            htmlContent += `
              <div style="font-size: 8px; color: #64748b; text-align: center; margin-top: 5px;">
                Showing 50 of ${data.length} records
              </div>
            `;
          }
          
          // Add summary if it's stock_in report
          if (reportType === 'stock_in' && data.length > 0) {
            const totalQty = data.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
            const totalValue = data.reduce((sum, item) => sum + (parseFloat(item.total_value) || 0), 0);
            htmlContent += `
              <div style="margin-top: 10px; padding: 8px; background: #f1f5f9; font-size: 8px; border-left: 3px solid #000;">
                <strong>Summary:</strong> Total Items: ${data.length} | Total Quantity: ${totalQty.toLocaleString()} | Total Value: ₱${totalValue.toFixed(2)}
              </div>
            `;
          }
        }
        
        htmlContent += `</div>`;
      }
      
      tempDiv.innerHTML = htmlContent;
      
      // Add to DOM temporarily
      document.body.appendChild(tempDiv);
      
      // Generate canvas from HTML
      const canvas = await html2canvas(tempDiv, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff'
      });
      
      // Remove temporary div
      document.body.removeChild(tempDiv);
      
      // Create PDF
      const imgData = canvas.toDataURL('image/png');
      const pdf = new jsPDF('p', 'mm', 'a4');
      const imgWidth = 210;
      const pageHeight = 295;
      const imgHeight = (canvas.height * imgWidth) / canvas.width;
      let heightLeft = imgHeight;
      
      let position = 0;
      
      pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
      heightLeft -= pageHeight;
      
      while (heightLeft >= 0) {
        position = heightLeft - imgHeight;
        pdf.addPage();
        pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
        heightLeft -= pageHeight;
      }
      
      // Save PDF
      const fileName = `Combined_Reports_${combineDateRange.startDate}_to_${combineDateRange.endDate}.pdf`;
      pdf.save(fileName);
      
      console.log(`PDF downloaded successfully: ${fileName}`);
      
    } catch (error) {
      console.error('Error generating PDF:', error);
      throw error;
    }
  };

  const openCombineModal = (reportType) => {
    setSelectedCombineReportType(reportType);
    setSelectedReportTypes(['all']); // Reset to all reports selected
    setCombineDateRange({
      startDate: new Date().toISOString().split('T')[0],
      endDate: new Date().toISOString().split('T')[0]
    });
    setShowCombineModal(true);
  };

  const handleReportTypeChange = (reportTypeId) => {
    if (reportTypeId === 'all') {
      setSelectedReportTypes(['all']);
    } else {
      setSelectedReportTypes(prev => {
        const newSelection = prev.filter(id => id !== 'all');
        if (newSelection.includes(reportTypeId)) {
          return newSelection.filter(id => id !== reportTypeId);
        } else {
          return [...newSelection, reportTypeId];
        }
      });
    }
  };

  useEffect(() => {
    // Initial fetch
    fetchReports();
    
    // Auto-clear notifications when Reports component is viewed
    markNotificationAsViewed('reports');
    clearSystemUpdates();

    // Auto-refresh every 10 seconds
    const refreshInterval = setInterval(() => {
      fetchReports();
    }, 10000);

    return () => clearInterval(refreshInterval);
  }, []);

  // Check for actual system updates (no automatic triggering)
  useEffect(() => {
    const checkForRealUpdates = async () => {
      try {
        const response = await fetch(API_BASE_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'check_reports_updates',
            hours: 1 // Check last hour for actual updates
          })
        });

        if (response.ok) {
          const result = await response.json();
          
          if (result.success && result.data && result.data.hasUpdates) {
            // Only update if there are actual updates
            updateSystemUpdates(true, result.data.count);
            updateReportsNotifications(true, result.data.count, result.data.reportUpdates || {});
            console.log('✅ Real reports updates detected:', result.data);
          } else {
            // Clear updates if no real updates
            updateSystemUpdates(false, 0);
            updateReportsNotifications(false, 0, {});
            console.log('✅ No real reports updates');
          }
        } else {
          // Clear updates if API fails
          updateSystemUpdates(false, 0);
          updateReportsNotifications(false, 0, {});
        }
      } catch (error) {
        console.error('❌ Error checking reports updates:', error);
        // Clear updates on error
        updateSystemUpdates(false, 0);
        updateReportsNotifications(false, 0, {});
      }
    };

    checkForRealUpdates();
  }, [updateSystemUpdates, updateReportsNotifications]);

  useEffect(() => {
    if (selectedReportType !== 'all') {
      fetchReportData(selectedReportType);
    } else {
      setReportData([]);
    }
  }, [selectedReportType, dateRange]);

  const getReportColumns = (reportType) => {
    switch (reportType) {
      case 'stock_in':
        return ['Date', 'Time', 'Product Name', 'Barcode', 'Quantity', 'Unit Price', 'Total Value', 'Supplier', 'Reference No', 'Received By'];
      case 'stock_out':
        return ['Date', 'Time', 'Product Name', 'Barcode', 'Quantity', 'Unit Price', 'Total Value', 'Cashier', 'Customer Info', 'Reference No'];
      case 'sales':
        return ['Date', 'Time', 'Transaction ID', 'Total Amount', 'Items Sold', 'Products', 'Terminal'];
      case 'inventory_balance':
        return ['Product Name', 'Barcode', 'Category', 'Current Stock', 'Unit Price', 'Total Value', 'Location', 'Supplier', 'Brand', 'Expiration', 'Status'];
      case 'supplier':
        return ['Supplier Name', 'Contact', 'Email', 'Products Supplied', 'Total Stock', 'Total Value', 'Deliveries Count'];
      case 'cashier_performance':
        return ['Date', 'Cashier Name', 'Transactions Count', 'Total Sales', 'Average Transaction', 'Unique Products Sold'];
      case 'login_logs':
        return ['Date', 'Time', 'Username', 'Role', 'Action', 'Description'];
      default:
        return [];
    }
  };

  const formatReportCell = (row, column, reportType) => {
    const columnKey = column.toLowerCase().replace(/\s+/g, '_');
    
    switch (columnKey) {
      case 'total_value':
      case 'total_amount':
      case 'unit_price':
        return `₱${parseFloat(row[columnKey] || 0).toFixed(2)}`;
      case 'date':
        return row[columnKey] ? new Date(row[columnKey]).toLocaleDateString('en-PH') : 'N/A';
      case 'time':
        return row[columnKey] ? new Date(`2000-01-01T${row[columnKey]}`).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' }) : 'N/A';
      case 'quantity':
        if (reportType === 'stock_in') {
          return (
            <span className="px-2 py-1 rounded text-sm font-medium" style={{ backgroundColor: theme.colors.successBg, color: theme.colors.success }}>
              +{row[columnKey] || '0'}
            </span>
          );
        }
        return row[columnKey] || '0';
      case 'current_stock':
      case 'items_sold':
      case 'transactions_count':
      case 'unique_products_sold':
      case 'products_supplied':
      case 'total_stock':
      case 'deliveries_count':
        return row[columnKey] || '0';
      case 'average_transaction':
        return `₱${parseFloat(row[columnKey] || 0).toFixed(2)}`;
      case 'status':
        const status = row[columnKey];
        if (status === 'Low Stock') return '⚠️ Low Stock';
        if (status === 'Out of Stock') return '❌ Out of Stock';
        if (status === 'In Stock') return '✅ In Stock';
        return status || 'N/A';
      default:
        return row[columnKey] || 'N/A';
    }
  };

  return (
    <div className="min-h-screen" style={{ backgroundColor: theme.bg.primary }}>
      {/* Header */}
      <div className="p-6" style={{ backgroundColor: theme.colors.accent }}>
        <div className="flex items-center justify-between">
          <div>
            <div className="flex items-center space-x-6 mb-4">
              <span className="border-b-2 pb-1" style={{ color: theme.text.primary, borderColor: theme.text.primary }}>System Reports</span>
              <span style={{ color: theme.text.secondary }}>Data Analytics</span>
              <span style={{ color: theme.text.secondary }}>Performance Metrics</span>
            </div>
            <h1 className="text-3xl font-bold" style={{ color: theme.text.primary }}>Reports Dashboard</h1>
          </div>
        </div>
      </div>

      {/* Reports Content */}
      <div className="p-6">
        {/* Report Type Selection */}
        <div className="mb-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {reportTypes.map((type) => (
              <div
                key={type.id}
                className={`p-4 rounded-lg border transition-all duration-200 hover:shadow-lg ${
                  selectedReportType === type.id ? 'ring-2 ring-blue-500' : ''
                }`}
                style={{
                  backgroundColor: selectedReportType === type.id ? theme.colors.accent : theme.bg.card,
                  borderColor: theme.border.default,
                  boxShadow: `0 4px 12px ${theme.shadow}`
                }}
              >
                <button
                  onClick={() => setSelectedReportType(type.id)}
                  className="w-full text-left"
                >
                  <div className="text-3xl mb-2">{type.icon}</div>
                  <div className="text-sm font-medium mb-2" style={{ color: theme.text.primary }}>
                    {type.name}
                  </div>
                </button>
                
                {/* Individual Action Buttons */}
                {type.id !== 'all' && (
                  <div className="space-y-2">
                    <button
                      onClick={() => openGenerateModal(type.id)}
                      disabled={reportDataLoading}
                      className="w-full px-3 py-2 rounded-md text-sm font-medium transition-all duration-200 hover:scale-105 disabled:opacity-50"
                      style={{
                        backgroundColor: theme.colors.accent,
                        color: theme.text.primary,
                        border: `1px solid ${theme.border.default}`
                      }}
                    >
                      📊 Generate Report
                    </button>
                    <button
                      onClick={() => openCombineModal(type.id)}
                      disabled={reportDataLoading}
                      className="w-full px-3 py-2 rounded-md text-sm font-medium transition-all duration-200 hover:scale-105 disabled:opacity-50"
                      style={{
                        backgroundColor: theme.bg.hover,
                        color: theme.text.secondary,
                        border: `1px solid ${theme.border.default}`
                      }}
                    >
                      📋 Combine Reports
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Date Range Selection */}
        {selectedReportType !== 'all' && (
          <div className="mb-6">
            <div
              className="rounded-lg shadow-md p-4"
              style={{
                backgroundColor: theme.bg.card,
                boxShadow: `0 10px 25px ${theme.shadow}`
              }}
            >
              <h3 className="text-lg font-semibold mb-3" style={{ color: theme.text.primary }}>Date Range</h3>
              <div className="flex gap-4 items-center">
                <div>
                  <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>Start Date</label>
                  <input
                    type="date"
                    value={dateRange.startDate}
                    onChange={(e) => setDateRange(prev => ({ ...prev, startDate: e.target.value }))}
                    className="px-3 py-2 border rounded-md"
                    style={{
                      backgroundColor: theme.bg.input,
                      borderColor: theme.border.default,
                      color: theme.text.primary
                    }}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1" style={{ color: theme.text.secondary }}>End Date</label>
                  <input
                    type="date"
                    value={dateRange.endDate}
                    onChange={(e) => setDateRange(prev => ({ ...prev, endDate: e.target.value }))}
                    className="px-3 py-2 border rounded-md"
                    style={{
                      backgroundColor: theme.bg.input,
                      borderColor: theme.border.default,
                      color: theme.text.primary
                    }}
                  />
                </div>
                <button
                  onClick={() => generateReport(selectedReportType)}
                  disabled={reportDataLoading}
                  className="px-4 py-2 rounded-md font-medium transition-all duration-200 hover:scale-105 disabled:opacity-50"
                  style={{
                    backgroundColor: theme.colors.accent,
                    color: theme.text.primary
                  }}
                >
                  {reportDataLoading ? 'Generating...' : 'Generate Report'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Report Data Display */}
        {selectedReportType === 'all' ? (
          <div
            className="rounded-lg shadow-md hover:shadow-lg transition-shadow duration-200"
            style={{
              backgroundColor: theme.bg.card,
              boxShadow: `0 10px 25px ${theme.shadow}`
            }}
          >
            <div className="p-6">
              <h3 className="text-lg font-semibold mb-4" style={{ color: theme.text.primary }}>Generated Reports</h3>

              {reportsLoading ? (
                <div className="flex items-center justify-center py-8">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: theme.colors.accent }}></div>
                  <span className="ml-2" style={{ color: theme.text.secondary }}>Loading reports...</span>
                </div>
              ) : reports.length === 0 ? (
                <div className="text-center py-12">
                  <div className="text-6xl mb-4">📊</div>
                  <h3 className="text-xl font-semibold mb-2" style={{ color: theme.text.primary }}>No Reports Found</h3>
                  <p className="mb-4" style={{ color: theme.text.secondary }}>
                    There are no generated reports available yet. Reports are automatically created from stock movements and transfers.
                  </p>
                  <div className="text-sm" style={{ color: theme.text.muted }}>
                    <p>💡 Tips:</p>
                    <ul className="list-disc list-inside mt-2 space-y-1">
                      <li>Stock movements create reports automatically</li>
                      <li>Transfer operations generate transfer reports</li>
                      <li>Use the report type buttons above to generate specific reports</li>
                    </ul>
                  </div>
                </div>
              ) : (
                <>
                  <div className="max-h-[520px] overflow-y-auto">
                    <table className="w-full caption-bottom text-sm">
                      <thead className="[&_tr]:border-b">
                        <tr className="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                          <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0" style={{ color: theme.text.primary }}>Report ID</th>
                          <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0" style={{ color: theme.text.primary }}>Report Type</th>
                          <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0" style={{ color: theme.text.primary }}>Generated Date</th>
                          <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0" style={{ color: theme.text.primary }}>Status</th>
                          <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0" style={{ color: theme.text.primary }}>Actions</th>
                        </tr>
                      </thead>
                      <tbody className="[&_tr:last-child]:border-0">
                        {reports.slice((reportsPage - 1) * itemsPerPage, reportsPage * itemsPerPage).map((report, index) => (
                          <tr key={index} className="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                            <td className="p-4 align-middle" style={{ color: theme.text.primary }}>{report.movement_id || 'N/A'}</td>
                            <td className="p-4 align-middle" style={{ color: theme.text.secondary }}>{report.type || 'N/A'}</td>
                            <td className="p-4 align-middle" style={{ color: theme.text.secondary }}>{report.date || 'N/A'}</td>
                            <td className="p-4 align-middle">
                              <span
                                className="px-2 py-1 rounded text-xs"
                                style={{
                                  backgroundColor: report.status === 'Completed' ? theme.colors.successBg : theme.colors.warningBg,
                                  color: report.status === 'Completed' ? theme.colors.success : theme.colors.warning
                                }}
                              >
                                {report.status || 'pending'}
                              </span>
                            </td>
                            <td className="p-4 align-middle">
                              <button
                                className="px-3 py-1 rounded text-sm border transition-all duration-200 hover:scale-105"
                                style={{
                                  backgroundColor: theme.bg.hover,
                                  borderColor: theme.border.default,
                                  color: theme.text.primary
                                }}
                              >
                                View
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {/* Pagination */}
                  <div className="flex items-center justify-between px-2 py-4">
                    <div className="text-sm text-muted-foreground" style={{ color: theme.text.muted }}>
                      Showing {Math.min((reportsPage - 1) * itemsPerPage + 1, reports.length)} to {Math.min(reportsPage * itemsPerPage, reports.length)} of {reports.length} reports
                    </div>
                    <div className="flex items-center gap-2">
                      <button
                        className={`inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-8 px-3 ${reportsPage === 1 ? 'opacity-50 cursor-not-allowed' : ''}`}
                        disabled={reportsPage === 1}
                        onClick={() => setReportsPage(p => Math.max(1, p - 1))}
                      >
                        ← Prev
                      </button>
                      {Array.from({ length: Math.max(1, Math.ceil(reports.length / itemsPerPage)) }, (_, i) => i + 1).map(page => (
                        <button
                          key={page}
                          onClick={() => setReportsPage(page)}
                          className={`inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input h-8 px-3 ${reportsPage === page ? 'bg-primary text-primary-foreground hover:bg-primary/90' : 'bg-background hover:bg-accent hover:text-accent-foreground'}`}
                        >
                          {page}
                        </button>
                      ))}
                      <button
                        className={`inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-8 px-3 ${reportsPage === Math.max(1, Math.ceil(reports.length / itemsPerPage)) ? 'opacity-50 cursor-not-allowed' : ''}`}
                        disabled={reportsPage === Math.max(1, Math.ceil(reports.length / itemsPerPage))}
                        onClick={() => setReportsPage(p => Math.min(Math.max(1, Math.ceil(reports.length / itemsPerPage)), p + 1))}
                      >
                        Next →
                      </button>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        ) : (
          <div
            className="rounded-lg shadow-md hover:shadow-lg transition-shadow duration-200"
            style={{
              backgroundColor: theme.bg.card,
              boxShadow: `0 10px 25px ${theme.shadow}`
            }}
          >
            <div className="p-6">
              <h3 className="text-lg font-semibold mb-4" style={{ color: theme.text.primary }}>
                {reportTypes.find(t => t.id === selectedReportType)?.name} - {dateRange.startDate} to {dateRange.endDate}
              </h3>

              {reportDataLoading ? (
                <div className="flex items-center justify-center py-8">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: theme.colors.accent }}></div>
                  <span className="ml-2" style={{ color: theme.text.secondary }}>Loading report data...</span>
                </div>
              ) : reportError ? (
                <div className="text-center py-8">
                  <div className="text-4xl mb-4">⚠️</div>
                  <p className="text-lg font-medium mb-2" style={{ color: theme.colors.danger }}>Error Loading Report</p>
                  <p className="mb-4" style={{ color: theme.text.secondary }}>{reportError}</p>
                  <button
                    onClick={() => fetchReportData(selectedReportType)}
                    className="px-4 py-2 rounded-md font-medium transition-all duration-200 hover:scale-105"
                    style={{
                      backgroundColor: theme.colors.accent,
                      color: theme.text.primary
                    }}
                  >
                    Try Again
                  </button>
                </div>
              ) : reportData.length > 0 ? (
                <>
                  {/* Summary Cards for Stock In Report */}
                  {selectedReportType === 'stock_in' && (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                      <div className="rounded-lg shadow-md p-4" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
                        <div className="flex items-center">
                          <div className="text-3xl mr-3">📦</div>
                          <div>
                            <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Total Items</p>
                            <p className="text-2xl font-bold" style={{ color: theme.text.primary }}>{reportData.length}</p>
                          </div>
                        </div>
                      </div>
                      <div className="rounded-lg shadow-md p-4" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
                        <div className="flex items-center">
                          <div className="text-3xl mr-3">📊</div>
                          <div>
                            <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Total Quantity</p>
                            <p className="text-2xl font-bold" style={{ color: theme.text.primary }}>
                              {reportData.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0).toLocaleString()}
                            </p>
                          </div>
                        </div>
                      </div>
                      <div className="rounded-lg shadow-md p-4" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
                        <div className="flex items-center">
                          <div className="text-3xl mr-3">💰</div>
                          <div>
                            <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Total Value</p>
                            <p className="text-2xl font-bold" style={{ color: theme.text.primary }}>
                              ₱{reportData.reduce((sum, item) => sum + (parseFloat(item.total_value) || 0), 0).toFixed(2)}
                            </p>
                          </div>
                        </div>
                      </div>
                      <div className="rounded-lg shadow-md p-4" style={{ backgroundColor: theme.bg.card, boxShadow: `0 10px 25px ${theme.shadow}` }}>
                        <div className="flex items-center">
                          <div className="text-3xl mr-3">🏢</div>
                          <div>
                            <p className="text-sm font-medium" style={{ color: theme.text.secondary }}>Suppliers</p>
                            <p className="text-2xl font-bold" style={{ color: theme.text.primary }}>
                              {new Set(reportData.map(item => item.supplier_name).filter(Boolean)).size}
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}

                  <div className="max-h-[600px] overflow-y-auto">
                    <table className="w-full caption-bottom text-sm">
                      <thead className="[&_tr]:border-b sticky top-0" style={{ backgroundColor: theme.bg.card }}>
                        <tr className="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                          {getReportColumns(selectedReportType).map((column, index) => (
                            <th key={index} className="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0" style={{ color: theme.text.primary }}>
                              {column}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody className="[&_tr:last-child]:border-0">
                        {reportData.map((row, index) => (
                          <tr key={index} className="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                            {getReportColumns(selectedReportType).map((column, colIndex) => (
                              <td key={colIndex} className="p-4 align-middle" style={{ color: theme.text.secondary }}>
                                {formatReportCell(row, column, selectedReportType)}
                              </td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              ) : (
                <div className="text-center py-8" style={{ color: theme.text.secondary }}>
                  <div className="text-4xl mb-4">📊</div>
                  <p>Click &ldquo;Generate Report&rdquo; to view {reportTypes.find(t => t.id === selectedReportType)?.name.toLowerCase()}</p>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Individual Generate Report Modal */}
      {showGenerateModal && (
        <div className="fixed inset-0 flex items-center justify-center z-50">
          <div 
            className="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 border-2 border-blue-300"
            style={{ 
              backgroundColor: theme.bg.card,
              boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(59, 130, 246, 0.2)'
            }}
          >
            {/* Modal Header */}
            <div className="flex items-center justify-between p-6 border-b" style={{ borderColor: theme.border.default }}>
              <div>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>
                  Generate {reportTypes.find(t => t.id === selectedGenerateReportType)?.name}
                </h3>
                <p className="text-sm mt-1" style={{ color: theme.text.secondary }}>
                  Select date range to generate the report
                </p>
              </div>
              <button
                onClick={() => setShowGenerateModal(false)}
                className="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Modal Content */}
            <div className="p-6">
              {/* Quick Select Date Range */}
              <div className="mb-6">
                <h4 className="text-sm font-medium mb-3" style={{ color: theme.text.primary }}>Quick Select</h4>
                <div className="grid grid-cols-2 gap-2">
                  {[
                    { label: 'Today', days: 0 },
                    { label: 'Yesterday', days: -1 },
                    { label: 'This Week', days: -7 },
                    { label: 'Last Week', days: -14 },
                    { label: 'This Month', days: -30 },
                    { label: 'Last Month', days: -60 }
                  ].map((option) => (
                    <button
                      key={option.label}
                      onClick={() => {
                        const today = new Date();
                        const targetDate = new Date(today.getTime() + (option.days * 24 * 60 * 60 * 1000));
                        const dateStr = targetDate.toISOString().split('T')[0];
                        
                        if (option.days === 0) {
                          setGenerateDateRange({ startDate: dateStr, endDate: dateStr });
                        } else if (option.days === -1) {
                          setGenerateDateRange({ startDate: dateStr, endDate: dateStr });
                        } else {
                          setGenerateDateRange({ startDate: dateStr, endDate: today.toISOString().split('T')[0] });
                        }
                      }}
                      className="px-3 py-2 text-sm rounded-md border transition-all duration-200 hover:scale-105"
                      style={{
                        backgroundColor: theme.bg.input,
                        borderColor: theme.border.default,
                        color: theme.text.primary
                      }}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Custom Date Range */}
              <div className="mb-6">
                <h4 className="text-sm font-medium mb-3" style={{ color: theme.text.primary }}>Custom Date Range</h4>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium mb-1" style={{ color: theme.text.secondary }}>Start Date</label>
                    <input
                      type="date"
                      value={generateDateRange.startDate}
                      onChange={(e) => setGenerateDateRange(prev => ({ ...prev, startDate: e.target.value }))}
                      className="w-full px-3 py-2 border rounded-md text-sm"
                      style={{
                        backgroundColor: theme.bg.input,
                        borderColor: theme.border.default,
                        color: theme.text.primary
                      }}
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium mb-1" style={{ color: theme.text.secondary }}>End Date</label>
                    <input
                      type="date"
                      value={generateDateRange.endDate}
                      onChange={(e) => setGenerateDateRange(prev => ({ ...prev, endDate: e.target.value }))}
                      className="w-full px-3 py-2 border rounded-md text-sm"
                      style={{
                        backgroundColor: theme.bg.input,
                        borderColor: theme.border.default,
                        color: theme.text.primary
                      }}
                    />
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex gap-3">
                <button
                  onClick={() => generateIndividualReport(selectedGenerateReportType)}
                  disabled={reportDataLoading}
                  className="flex-1 px-4 py-2 rounded-md font-medium transition-all duration-200 hover:scale-105 disabled:opacity-50 flex items-center justify-center gap-2"
                  style={{
                    backgroundColor: theme.colors.accent,
                    color: theme.text.primary
                  }}
                >
                  {reportDataLoading ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-current"></div>
                      Generating...
                    </>
                  ) : (
                    <>
                      📊 Generate Report
                    </>
                  )}
                </button>
                <button
                  onClick={() => setShowGenerateModal(false)}
                  className="px-4 py-2 rounded-md font-medium transition-all duration-200 hover:scale-105 flex items-center justify-center gap-2"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.secondary,
                    border: `1px solid ${theme.border.default}`
                  }}
                >
                  ✕ Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Individual Combine Reports Modal */}
      {showCombineModal && (
        <div className="fixed inset-0 flex items-center justify-center z-50">
          <div 
            className="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 border-2 border-purple-300"
            style={{ 
              backgroundColor: theme.bg.card,
              boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(147, 51, 234, 0.2)'
            }}
          >
            {/* Modal Header */}
            <div className="flex items-center justify-between p-6 border-b" style={{ borderColor: theme.border.default }}>
              <div>
                <h3 className="text-lg font-semibold" style={{ color: theme.text.primary }}>
                  Combine Reports
                </h3>
                <p className="text-sm mt-1" style={{ color: theme.text.secondary }}>
                  Select date range and report types to download as a single PDF
                </p>
              </div>
              <button
                onClick={() => setShowCombineModal(false)}
                className="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Modal Content */}
            <div className="p-6">
              {/* Quick Select Date Range */}
              <div className="mb-6">
                <h4 className="text-sm font-medium mb-3" style={{ color: theme.text.primary }}>Quick Select</h4>
                <div className="grid grid-cols-2 gap-2">
                  {[
                    { label: 'Today', days: 0 },
                    { label: 'Yesterday', days: -1 },
                    { label: 'This Week', days: -7 },
                    { label: 'Last Week', days: -14 },
                    { label: 'This Month', days: -30 },
                    { label: 'Last Month', days: -60 }
                  ].map((option) => (
                    <button
                      key={option.label}
                      onClick={() => {
                        const today = new Date();
                        const targetDate = new Date(today.getTime() + (option.days * 24 * 60 * 60 * 1000));
                        const dateStr = targetDate.toISOString().split('T')[0];
                        
                        if (option.days === 0) {
                          setCombineDateRange({ startDate: dateStr, endDate: dateStr });
                        } else if (option.days === -1) {
                          setCombineDateRange({ startDate: dateStr, endDate: dateStr });
                        } else {
                          setCombineDateRange({ startDate: dateStr, endDate: today.toISOString().split('T')[0] });
                        }
                      }}
                      className="px-3 py-2 text-sm rounded-md border transition-all duration-200 hover:scale-105"
                      style={{
                        backgroundColor: theme.bg.input,
                        borderColor: theme.border.default,
                        color: theme.text.primary
                      }}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Custom Date Range */}
              <div className="mb-6">
                <h4 className="text-sm font-medium mb-3" style={{ color: theme.text.primary }}>Custom Date Range</h4>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium mb-1" style={{ color: theme.text.secondary }}>Start Date</label>
                    <input
                      type="date"
                      value={combineDateRange.startDate}
                      onChange={(e) => setCombineDateRange(prev => ({ ...prev, startDate: e.target.value }))}
                      className="w-full px-3 py-2 border rounded-md text-sm"
                      style={{
                        backgroundColor: theme.bg.input,
                        borderColor: theme.border.default,
                        color: theme.text.primary
                      }}
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium mb-1" style={{ color: theme.text.secondary }}>End Date</label>
                    <input
                      type="date"
                      value={combineDateRange.endDate}
                      onChange={(e) => setCombineDateRange(prev => ({ ...prev, endDate: e.target.value }))}
                      className="w-full px-3 py-2 border rounded-md text-sm"
                      style={{
                        backgroundColor: theme.bg.input,
                        borderColor: theme.border.default,
                        color: theme.text.primary
                      }}
                    />
                  </div>
                </div>
              </div>

              {/* Report Type Selection */}
              <div className="mb-6">
                <h4 className="text-sm font-medium mb-3" style={{ color: theme.text.primary }}>Report Types to Combine</h4>
                <div className="space-y-2">
                  {reportTypes.filter(type => type.id !== 'all').map((type) => (
                    <label key={type.id} className="flex items-center space-x-2">
                      <input
                        type="checkbox"
                        checked={selectedReportTypes.includes(type.id)}
                        onChange={() => handleReportTypeChange(type.id)}
                        className="rounded border-gray-300"
                      />
                      <span className="text-sm" style={{ color: theme.text.secondary }}>
                        {type.name}
                      </span>
                    </label>
                  ))}
                  <label className="flex items-center space-x-2">
                    <input
                      type="checkbox"
                      checked={selectedReportTypes.includes('all')}
                      onChange={() => handleReportTypeChange('all')}
                      className="rounded border-gray-300"
                    />
                    <span className="text-sm font-medium" style={{ color: theme.text.primary }}>
                      All Reports
                    </span>
                  </label>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex gap-3">
                <button
                  onClick={combineIndividualReports}
                  disabled={reportDataLoading}
                  className="flex-1 px-4 py-2 rounded-md font-medium transition-all duration-200 hover:scale-105 disabled:opacity-50 flex items-center justify-center gap-2"
                  style={{
                    backgroundColor: theme.colors.accent,
                    color: theme.text.primary
                  }}
                >
                  {reportDataLoading ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-current"></div>
                      Generating PDF...
                    </>
                  ) : (
                    <>
                      📋 Download PDF
                    </>
                  )}
                </button>
                <button
                  onClick={() => setShowCombineModal(false)}
                  className="px-4 py-2 rounded-md font-medium transition-all duration-200 hover:scale-105 flex items-center justify-center gap-2"
                  style={{
                    backgroundColor: theme.bg.hover,
                    borderColor: theme.border.default,
                    color: theme.text.secondary,
                    border: `1px solid ${theme.border.default}`
                  }}
                >
                  ✕ Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default Reports;
