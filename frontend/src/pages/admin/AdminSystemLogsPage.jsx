import React, { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { FiDownload, FiPrinter } from "react-icons/fi";
import { adminToast } from "../../lib/notifications";
import { adminApi } from "../../lib/api/adminApi";
import { queryKeys } from "../../lib/react-query/queryKeys";
import ActivityLogView from "../../components/logs/ActivityLogView";

/**
 * Admin System Logs (#94): newest first, names and Manila time, filtered by
 * user, role, action text and dates; Export and Print use the same filters.
 */
const AdminSystemLogsPage = () => {
  const [exporting, setExporting] = useState(false);

  const usersQuery = useQuery({
    queryKey: queryKeys.admin.logUsers(),
    queryFn: adminApi.getSystemLogUsers,
    select: (res) => res?.users ?? [],
  });

  const withPdf = async (filters, open) => {
    setExporting(true);
    try {
      const blob = await adminApi.exportSystemLogsPdf(filters);
      open(URL.createObjectURL(blob));
    } catch {
      adminToast.error("Export failed", "Could not generate the audit log PDF.");
    } finally {
      setExporting(false);
    }
  };

  const handleExportPdf = (filters) => withPdf(filters, (url) => {
    const a = document.createElement("a");
    a.href = url;
    a.download = `AUDIT_LOG_${new Date().toISOString().slice(0, 10)}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    adminToast.success("Export ready", "Audit log PDF downloaded.");
  });

  const handlePrint = (filters) => withPdf(filters, (url) => {
    const win = window.open(url, "_blank");
    if (!win) adminToast.warning("Pop-up blocked", "Allow pop-ups to print the audit log.");
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  });

  return (
    <>
      <section className="mb-4">
        <h2 className="m-0 text-2xl font-bold text-gray-800">System Logs</h2>
        <p className="mt-1 m-0 text-gray-600 text-sm">
          Every sign-in, account change, settings change and record change, newest first. The log can't be edited.
        </p>
      </section>

      <ActivityLogView
        queryKey={(params) => queryKeys.admin.logsList(params)}
        fetchPage={(params) => adminApi.getSystemLogs(params)}
        users={usersQuery.data ?? []}
        actions={(filters, datesInvalid) => (
          <>
            <button
              type="button"
              onClick={() => handlePrint(filters)}
              disabled={exporting || datesInvalid}
              className="inline-flex items-center gap-2 py-2 px-4 rounded-lg border border-gray-300 bg-white text-gray-700 text-sm font-medium hover:bg-gray-50 disabled:opacity-60 disabled:cursor-not-allowed"
            >
              <FiPrinter className="w-4 h-4" />
              Print
            </button>
            <button
              type="button"
              onClick={() => handleExportPdf(filters)}
              disabled={exporting || datesInvalid}
              className="inline-flex items-center gap-2 py-2 px-4 rounded-lg bg-tmcc text-white text-sm font-medium hover:bg-tmcc-dark disabled:opacity-60 disabled:cursor-not-allowed"
            >
              <FiDownload className="w-4 h-4" />
              {exporting ? "Generating…" : "Export PDF"}
            </button>
          </>
        )}
      />
    </>
  );
};

export default AdminSystemLogsPage;
