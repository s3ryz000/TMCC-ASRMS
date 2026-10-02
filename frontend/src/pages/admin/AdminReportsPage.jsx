import React, { useState, useEffect } from "react";
import {  FiUsers,FiClock, FiDownload } from "react-icons/fi";
import { adminToast } from "../../lib/notifications";
import { staffApi } from "../../lib/api/staffApi";
import { parseApiError } from "../../lib/api/errors";

function escapeCsvCell(val) {
  const s = val == null ? "" : String(val);
  if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

function downloadCsv(filename, rows, headers) {
  const lines = [
    headers.map(escapeCsvCell).join(","),
    ...rows.map((row) => row.map(escapeCsvCell).join(",")),
  ];
  const blob = new Blob([lines.join("\r\n")], {
    type: "text/csv;charset=utf-8;",
  });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

function mapHistoryRow(r) {
  return {
    id: r.id,
    user_name: r.user_name || "System",
    action: r.action || "—",
    role: r.role || "—",
    date: r.date || "—",
    time: r.time || "",
  };
}

function openPrintableExport(exportData, summary) {
  const w = window.open("", "_blank");
  if (!w) {
    adminToast.error("Popup blocked", "Allow popups to print or save as PDF.");
    return;
  }

  const esc = (s) =>
    String(s ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");

  const content = exportData
    .map(
      (row, i) => `
      <p>
        ${i + 1}. <strong>${esc(row.user_name || "System")}</strong> 
        performed <strong>${esc(row.action)}</strong> 
        as <strong>${esc(row.role)}</strong> 
        on ${esc(row.date)} at ${esc(row.time)}.
      </p>
    `,
    )
    .join("");

  w.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8"/>
      <title>System Log Report</title>
      <style>
        body {
          font-family: Arial, sans-serif;
          padding: 40px;
          color: #111;
          line-height: 1.6;
        }

        .header {
          display: flex;
          align-items: center;
          gap: 15px;
          margin-bottom: 20px;
          border-bottom: 2px solid #000;
          padding-bottom: 10px;
        }

        .logo {
          width: 60px;
          height: 60px;
          object-fit: contain;
        }

        .title {
          font-size: 18px;
          font-weight: bold;
        }

        .subtitle {
          font-size: 13px;
          color: #555;
        }

        .date {
          margin-top: 10px;
          font-size: 12px;
          color: #555;
        }

        .content {
          margin-top: 20px;
          font-size: 14px;
          text-align: justify;
        }

        .intro {
          margin-top: 15px;
          font-size: 14px;
        }

        p {
          margin-bottom: 12px;
        }
      </style>
    </head>
    <body>

      <!-- HEADER -->
      <div class="header">
        <img src="/logo.png" class="logo" />
        <div>
          <div class="title">System Log Report</div>
          <div class="subtitle">Student Records Management System</div>
        </div>
      </div>

      <!-- DATE -->
      <div class="date">
        Generated on ${new Date().toLocaleString()}
      </div>

      <div class="intro">
        <p>
          Today, a total of 
          <strong>${esc(summary?.processed_today ?? "0")}</strong> 
          transactions were processed in the system. 
          The system currently holds 
          <strong>${esc(summary?.students_count ?? "0")}</strong> 
          registered students.
        </p>
      </div>

      <div class="content">
        ${
          exportData.length === 0
            ? "<p>No system logs recorded for today.</p>"
            : `<p>The following system activities were recorded:</p>${content}`
        }
      </div>

    </body>
    </html>
  `);

  w.document.close();
  w.onload = () => {
    w.focus();
    w.print();
  };
}
const AdminReportsPage = () => {
  const [summary, setSummary] = useState(null);
  const [recentRequests, setRecentRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [exportLoading, setExportLoading] = useState(false);
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
  });
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    Promise.all([staffApi.getReportsSummary()])
      .then(([summaryRes]) => {
        if (cancelled) return;
        setSummary(summaryRes || {});
      })
      .catch((err) => {
        if (!cancelled) {
          const parsed = parseApiError(err);
          setLoadError(parsed.message || "Failed to load reports.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const fetchHistory = async (page = 1) => {
    try {
      setLoading(true);

      const res = await staffApi.getTransactionHistory({
        per_page: 10,
        page: page,
      });

      const list = Array.isArray(res?.data) ? res.data : [];

      setRecentRequests(list.map(mapHistoryRow));

      setPagination({
        current_page: res.current_page,
        last_page: res.last_page,
      });
    } catch (err) {
      const parsed = parseApiError(err);
      setLoadError(parsed.message || "Failed to load reports.");
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    fetchHistory(1);
  }, []);

  const runExport = async (kind) => {
    setExportLoading(true);
    try {
      const exportData = recentRequests;
      const summaryExport = summary;

      if (kind === "csv") {
        const headers = [
          "Student Number",
          "Student Name",
          "Record Type",
          "Purpose",
          "Status",
          "Date Requested",
          "Date Processed",
          "Date Released",
        ];

        const rows = exportData.map((row) => [
          row.student_number,
          row.student_name,
          row.record_type,
          row.purpose,
          row.status,
          row.requested_at,
          row.processed_at,
          row.released_at,
        ]);

        const summaryRows = summaryExport
          ? [
              ["REPORT SUMMARY"],
              ["Processed Today", summaryExport.processed_today ?? "—"],
              ["Total Students", summaryExport.students_count ?? "—"],
              [],
            ]
          : [];

        const finalRows = [...summaryRows, headers, ...rows];

        const stamp = new Date()
          .toISOString()
          .slice(0, 19)
          .replace(/[:T]/g, "-");

        downloadCsv(`reports_export_${stamp}.csv`, finalRows, []);
      } else {
        openPrintableExport(exportData, summaryExport);
        adminToast.success(
          "Print dialog opened",
          "Use your browser to save as PDF.",
        );
      }
    } catch (err) {
      const parsed = parseApiError(err);
      adminToast.error(
        "Export failed",
        parsed.message || "Could not export reports.",
      );
    } finally {
      setExportLoading(false);
    }
  };

  const handleExportCSV = () => runExport("csv");
  const handleExportPDF = () => runExport("pdf");

  return (
    <>
      <section className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="m-0 text-2xl font-bold text-gray-800">Reports</h2>
          <p className="mt-1 m-0 text-gray-600 text-sm">
            Full reports with export. Admin-only access.
          </p>
        </div>
        <div className="flex gap-2">
        
          <button
            type="button"
            onClick={handleExportPDF}
            disabled={exportLoading}
            className="inline-flex items-center gap-2 py-2.5 px-4 rounded-lg text-sm font-medium bg-staff-red text-white hover:opacity-90 disabled:opacity-70"
          >
            <FiDownload /> Export PDF
          </button>
        </div>
      </section>

      {loadError && (
        <div
          className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"
          role="alert"
        >
          {loadError}
        </div>
      )}

      <section className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden mb-6">
        <div className="p-6 border-b border-gray-100">
          <h3 className="mt-0 mb-2 text-lg font-semibold text-gray-800">
            Summary
          </h3>
          <p className="m-0 text-sm text-gray-500">
            Key performance indicators from live data.
          </p>
        </div>
        {loading && !summary ? (
          <div className="p-8 text-center text-gray-500">
            Loading summary...
          </div>
        ) : (
          <div className="p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="p-5 rounded-xl bg-gray-50 border-l-4 border-blue-500">
              <FiClock className="w-7 h-7 mb-2 text-blue-500" aria-hidden />
              <h4 className="m-0 mb-2 text-sm text-gray-500 font-medium">
                Processed Today
              </h4>
              <p className="m-0 text-2xl font-bold text-gray-800">
                {summary?.processed_today ?? "—"}
              </p>
              <small className="block mt-1 text-gray-400 text-xs">
                Created Student Today
              </small>
            </div>
            <div className="p-5 rounded-xl bg-gray-50 border-l-4 border-indigo-500">
              <FiUsers className="w-7 h-7 mb-2 text-indigo-500" aria-hidden />
              <h4 className="m-0 mb-2 text-sm text-gray-500 font-medium">
                Students
              </h4>
              <p className="m-0 text-2xl font-bold text-gray-800">
                {summary?.students_count ?? "—"}
              </p>
              <small className="block mt-1 text-gray-400 text-xs">
                Total in system
              </small>
            </div>
          </div>
        )}
      </section>

      <section className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-100">
          <h3 className="mt-0 mb-2 text-lg font-semibold text-gray-800">
            Log History
          </h3>
        </div>
        <div className="overflow-x-auto">
          <table
            className="w-full text-sm border-collapse"
            aria-label="Transaction history"
          >
            <thead>
              <tr>
                <th>User</th>
                <th>Action</th>
                <th>Role</th>
                <th>Date</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-gray-500">
                    Loading...
                  </td>
                </tr>
              ) : recentRequests.length === 0 ? (
                <tr>
                  <td
                    colSpan={5}
                    className="py-6 text-center text-gray-500 italic"
                  >
                    No transactions yet.
                  </td>
                </tr>
              ) : (
                recentRequests.map((row) => (
                  <tr key={row.id}>
                    <td className="py-3 px-4">{row.user_name}</td>
                    <td className="py-3 px-4">{row.action}</td>
                    <td className="py-3 px-4">{row.role}</td>
                    <td className="py-3 px-4">{row.date}</td>
                    <td className="py-3 px-4">{row.time}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        <div className="px-6 py-3 border-t border-gray-100 bg-gray-50 text-sm text-gray-500 flex items-center justify-between">
          <div className="flex gap-2 justify-center py-4">
            {Array.from({ length: pagination.last_page }, (_, i) => i + 1).map(
              (p) => (
                <button
                  key={p}
                  onClick={() => fetchHistory(p)}
                  className={`px-3 py-1 rounded ${
                    p === pagination.current_page
                      ? "bg-tmcc text-white"
                      : "bg-gray-200"
                  }`}
                >
                  {p}
                </button>
              ),
            )}
          </div>
        </div>
      </section>
    </>
  );
};

export default AdminReportsPage;
