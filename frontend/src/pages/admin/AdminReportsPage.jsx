import React, { useCallback, useEffect, useState } from "react";
import { FiActivity, FiCheckCircle, FiClock, FiDownload, FiFileText, FiInbox } from "react-icons/fi";
import { adminToast } from "../../lib/notifications";
import { adminApi } from "../../lib/api/adminApi";
import { staffApi } from "../../lib/api/staffApi";
import { parseApiError } from "../../lib/api/errors";
import { localDateString } from "../../lib/tools";
import {
  STATUS_LABELS,
  buildCsvRows,
  buildPrintHtml,
  downloadCsv,
  formatDays,
  formatRate,
  rangeLabel,
  recordTypeLabel,
  roleLabel,
} from "../../lib/reportExport";

const firstOfThisMonth = () => {
  const d = new Date();
  return localDateString(new Date(d.getFullYear(), d.getMonth(), 1));
};

const thClass = "py-3 px-4 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700";
const tdClass = "py-2.5 px-4 border-b border-gray-100";
const cardClass = "bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden mb-6";

const Kpi = ({ icon: Icon, label, value, color }) => (
  <div className={`p-5 rounded-xl bg-gray-50 border-l-4 ${color.border}`}>
    <Icon className={`w-6 h-6 mb-2 ${color.text}`} aria-hidden />
    <h4 className="m-0 mb-1 text-sm text-gray-500 font-medium">{label}</h4>
    <p className="m-0 text-2xl font-bold text-gray-800">{value}</p>
  </div>
);

const CountTable = ({ caption, headers, rows }) => (
  <div className="overflow-x-auto">
    <table className="w-full text-sm border-collapse" aria-label={caption}>
      <thead>
        <tr>
          <th className={thClass}>{headers[0]}</th>
          <th className={`${thClass} text-right`}>{headers[1]}</th>
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr>
            <td colSpan={2} className={`${tdClass} text-center text-gray-500 italic`}>
              None in this period.
            </td>
          </tr>
        ) : (
          rows.map(([label, count]) => (
            <tr key={label}>
              <td className={tdClass}>{label}</td>
              <td className={`${tdClass} text-right font-medium`}>{count}</td>
            </tr>
          ))
        )}
      </tbody>
    </table>
  </div>
);

/**
 * Admin reports (#96): record requests and system activity for a date range,
 * with CSV and PDF export of the same range.
 */
const AdminReportsPage = () => {
  const [dateFrom, setDateFrom] = useState(firstOfThisMonth);
  const [dateTo, setDateTo] = useState(() => localDateString());
  const [range, setRange] = useState(() => ({ date_from: firstOfThisMonth(), date_to: localDateString() }));
  const [rangeError, setRangeError] = useState(null);

  const [requests, setRequests] = useState(null);
  const [activity, setActivity] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [exportLoading, setExportLoading] = useState(false);

  const [history, setHistory] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(true);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    Promise.all([adminApi.getRequestsReport(range), adminApi.getActivityReport(range)])
      .then(([req, act]) => {
        if (cancelled) return;
        setRequests(req);
        setActivity(act);
      })
      .catch((err) => {
        if (!cancelled) setLoadError(parseApiError(err).message || "Failed to load reports.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [range]);

  const fetchHistory = useCallback(
    async (page = 1) => {
      setHistoryLoading(true);
      try {
        const res = await staffApi.getTransactionHistory({ ...range, per_page: 10, page });
        setHistory(Array.isArray(res?.data) ? res.data : []);
        setPagination({ current_page: res.current_page, last_page: res.last_page, total: res.total });
      } catch (err) {
        setLoadError(parseApiError(err).message || "Failed to load the log history.");
      } finally {
        setHistoryLoading(false);
      }
    },
    [range],
  );

  useEffect(() => {
    fetchHistory(1);
  }, [fetchHistory]);

  const applyRange = (e) => {
    e.preventDefault();
    if (dateFrom && dateTo && dateTo < dateFrom) {
      setRangeError("The end date must be on or after the start date.");
      return;
    }
    setRangeError(null);
    setRange({ date_from: dateFrom || undefined, date_to: dateTo || undefined });
  };

  const runExport = async (kind) => {
    if (!requests || !activity) return;
    setExportLoading(true);
    try {
      // The request list for the same range as the figures on screen.
      const exported = await adminApi.exportReports(range);
      const input = { range, requests, activity, exportRows: exported?.export_data || [] };
      const suffix = `${range.date_from || "start"}_to_${range.date_to || "today"}`;

      if (kind === "csv") {
        downloadCsv(`asrms_report_${suffix}.csv`, buildCsvRows(input));
        adminToast.success("CSV downloaded", `Report for ${rangeLabel(range)}.`);
        return;
      }

      const w = window.open("", "_blank");
      if (!w) {
        adminToast.error("Popup blocked", "Allow popups to print or save as PDF.");
        return;
      }
      w.document.write(buildPrintHtml(input));
      w.document.close();
      w.onload = () => {
        w.focus();
        w.print();
      };
      adminToast.success("Print dialog opened", "Choose \"Save as PDF\" to keep a copy.");
    } catch (err) {
      adminToast.error("Export failed", parseApiError(err).message || "Could not export the report.");
    } finally {
      setExportLoading(false);
    }
  };

  const ready = !loading && requests && activity;
  const exportDisabled = exportLoading || !ready;

  return (
    <>
      <section className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="m-0 text-2xl font-bold text-gray-800">Reports</h2>
          <p className="mt-1 m-0 text-gray-600 text-sm">
            Record requests and system activity for a period. Figures are counted from the database when the page loads.
          </p>
        </div>
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => runExport("csv")}
            disabled={exportDisabled}
            className="inline-flex items-center gap-2 py-2.5 px-4 rounded-lg text-sm font-medium bg-white border border-gray-300 text-gray-800 hover:bg-gray-50 disabled:opacity-60"
          >
            <FiDownload /> Export CSV
          </button>
          <button
            type="button"
            onClick={() => runExport("pdf")}
            disabled={exportDisabled}
            className="inline-flex items-center gap-2 py-2.5 px-4 rounded-lg text-sm font-medium bg-staff-red text-white hover:opacity-90 disabled:opacity-60"
          >
            <FiDownload /> Export PDF
          </button>
        </div>
      </section>

      <form onSubmit={applyRange} className={`${cardClass} p-5 flex flex-wrap items-end gap-4`} aria-label="Report period">
        <div>
          <label htmlFor="report-date-from" className="block text-sm font-medium text-gray-700 mb-1">From</label>
          <input
            id="report-date-from"
            type="date"
            value={dateFrom}
            max={dateTo || undefined}
            onChange={(e) => setDateFrom(e.target.value)}
            className="py-2 px-3 rounded-lg border border-gray-300 text-sm"
          />
        </div>
        <div>
          <label htmlFor="report-date-to" className="block text-sm font-medium text-gray-700 mb-1">To</label>
          <input
            id="report-date-to"
            type="date"
            value={dateTo}
            min={dateFrom || undefined}
            onChange={(e) => setDateTo(e.target.value)}
            className="py-2 px-3 rounded-lg border border-gray-300 text-sm"
          />
        </div>
        <button
          type="submit"
          className="py-2 px-4 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark"
        >
          Show report
        </button>
        <p className="m-0 text-sm text-gray-500 basis-full">
          Showing <strong>{rangeLabel(range)}</strong>. Both dates are included. Leave a date empty for no limit.
        </p>
        {rangeError && (
          <p className="m-0 text-sm text-red-600 basis-full" role="alert">{rangeError}</p>
        )}
      </form>

      {loadError && (
        <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          {loadError}
        </div>
      )}

      <section className={cardClass} aria-labelledby="report-requests-title">
        <div className="p-6 border-b border-gray-100">
          <h3 id="report-requests-title" className="mt-0 mb-1 text-lg font-semibold text-gray-800">Record requests</h3>
          <p className="m-0 text-sm text-gray-500">Requests made in the period, by their current status.</p>
        </div>
        {!ready ? (
          <div className="p-8 text-center text-gray-500">Loading...</div>
        ) : (
          <div className="p-6 space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Kpi icon={FiInbox} label="Total requests" value={requests.total} color={{ border: "border-blue-500", text: "text-blue-500" }} />
              <Kpi
                icon={FiCheckCircle}
                label="Approval rate"
                value={formatRate(requests.approval_rate)}
                color={{ border: "border-green-500", text: "text-green-500" }}
              />
              <Kpi
                icon={FiClock}
                label="Average processing time"
                value={formatDays(requests.avg_processing_time_days)}
                color={{ border: "border-indigo-500", text: "text-indigo-500" }}
              />
            </div>
            <p className="m-0 text-xs text-gray-500">
              Approval rate: approved and released requests ÷ all decided requests (approved, released and rejected). Pending requests are not counted.
            </p>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <CountTable
                caption="Requests by status"
                headers={["Status", "Requests"]}
                rows={Object.keys(STATUS_LABELS).map((s) => [STATUS_LABELS[s], requests.by_status?.[s] ?? 0])}
              />
              <CountTable
                caption="Requests by record type"
                headers={["Record type", "Requests"]}
                rows={(requests.by_record_type || []).map((r) => [recordTypeLabel(r.record_type), r.total])}
              />
            </div>
          </div>
        )}
      </section>

      <section className={cardClass} aria-labelledby="report-activity-title">
        <div className="p-6 border-b border-gray-100">
          <h3 id="report-activity-title" className="mt-0 mb-1 text-lg font-semibold text-gray-800">System activity</h3>
          <p className="m-0 text-sm text-gray-500">Entries written to the system log in the period.</p>
        </div>
        {!ready ? (
          <div className="p-8 text-center text-gray-500">Loading...</div>
        ) : (
          <div className="p-6 space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Kpi icon={FiActivity} label="Log entries" value={activity.total} color={{ border: "border-amber-500", text: "text-amber-500" }} />
              <Kpi
                icon={FiFileText}
                label="Days with activity"
                value={(activity.by_day || []).length}
                color={{ border: "border-slate-500", text: "text-slate-500" }}
              />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <CountTable
                caption="Entries by role"
                headers={["Role", "Entries"]}
                rows={(activity.by_role || []).map((r) => [roleLabel(r.role), r.total])}
              />
              <CountTable
                caption="Top actions"
                headers={["Top actions", "Times"]}
                rows={(activity.top_actions || []).map((r) => [r.action, r.total])}
              />
              <CountTable
                caption="Entries by day"
                headers={["Date", "Entries"]}
                rows={(activity.by_day || []).map((r) => [r.date, r.total])}
              />
            </div>
          </div>
        )}
      </section>

      <section className={cardClass} aria-labelledby="report-history-title">
        <div className="p-6 border-b border-gray-100">
          <h3 id="report-history-title" className="mt-0 mb-1 text-lg font-semibold text-gray-800">Log history</h3>
          <p className="m-0 text-sm text-gray-500">
            {pagination.total ?? 0} entries in the period, newest first.
          </p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm border-collapse" aria-label="Log history">
            <thead>
              <tr>
                <th className={thClass}>User</th>
                <th className={thClass}>Action</th>
                <th className={thClass}>Role</th>
                <th className={thClass}>Date</th>
                <th className={thClass}>Time</th>
              </tr>
            </thead>
            <tbody>
              {historyLoading ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-gray-500">Loading...</td>
                </tr>
              ) : history.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-gray-500 italic">No log entries in this period.</td>
                </tr>
              ) : (
                history.map((row) => (
                  <tr key={row.id}>
                    <td className={tdClass}>{row.user_name || "System"}</td>
                    <td className={tdClass}>{row.action || "—"}</td>
                    <td className={tdClass}>{row.role || "—"}</td>
                    <td className={tdClass}>{row.date || "—"}</td>
                    <td className={tdClass}>{row.time || ""}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {pagination.last_page > 1 && (
          <div className="px-6 py-3 border-t border-gray-100 bg-gray-50 flex flex-wrap gap-2">
            {Array.from({ length: pagination.last_page }, (_, i) => i + 1).map((p) => (
              <button
                key={p}
                type="button"
                onClick={() => fetchHistory(p)}
                aria-current={p === pagination.current_page ? "page" : undefined}
                className={`px-3 py-1 rounded text-sm ${p === pagination.current_page ? "bg-tmcc text-white" : "bg-gray-200"}`}
              >
                {p}
              </button>
            ))}
          </div>
        )}
      </section>
    </>
  );
};

export default AdminReportsPage;
