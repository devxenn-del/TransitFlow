import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';

import { AuthProvider } from './auth/AuthContext.jsx';
import AppLayout from './components/AppLayout.jsx';
import LegalConsentGate from './components/LegalConsentGate.jsx';
import ProtectedRoute from './components/ProtectedRoute.jsx';
import { useAuth } from './auth/AuthContext.jsx';
import Dashboard from './pages/Dashboard.jsx';
import ForcePasswordChange from './pages/ForcePasswordChange.jsx';
import Login from './pages/Login.jsx';
import MyProfile from './pages/MyProfile.jsx';
import NotFound from './pages/NotFound.jsx';
import ReceiptView from './pages/ReceiptView.jsx';
import LegalDocumentPage from './pages/public/LegalDocumentPage.jsx';
import Attendance from './pages/company/Attendance.jsx';
import AdminAssignments from './pages/company/AdminAssignments.jsx';
import Buses from './pages/company/Buses.jsx';
import ThermalPrinters from './pages/company/ThermalPrinters.jsx';
import CashCounts from './pages/company/CashCounts.jsx';
import CompanyProfile from './pages/company/CompanyProfile.jsx';
import CompanySettings from './pages/company/CompanySettings.jsx';
import Devices from './pages/company/Devices.jsx';
import MobileApp from './pages/company/MobileApp.jsx';
import CompanyUsers from './pages/company/CompanyUsers.jsx';
import DataTools from './pages/company/DataTools.jsx';
import AuditLog from './pages/company/AuditLog.jsx';
import Expenses from './pages/company/Expenses.jsx';
import FuelEnergy from './pages/company/FuelEnergy.jsx';
import LiveMonitor from './pages/company/LiveMonitor.jsx';
import ConductorTrip from './pages/conductor/ConductorTrip.jsx';
import Drivers from './pages/company/Drivers.jsx';
import FareMatrixGrid from './pages/company/FareMatrixGrid.jsx';
import Franchises from './pages/company/Franchises.jsx';
import PassengerTypes from './pages/company/PassengerTypes.jsx';
import Remittances from './pages/company/Remittances.jsx';
import CashCountReport from './pages/company/reports/CashCountReport.jsx';
import DailyOperations from './pages/company/reports/DailyOperations.jsx';
import ExpenseReport from './pages/company/reports/ExpenseReport.jsx';
import FuelEnergyReport from './pages/company/reports/FuelEnergyReport.jsx';
import IncomeMonitoring from './pages/company/reports/IncomeMonitoring.jsx';
import Roles from './pages/company/Roles.jsx';
import Terminals from './pages/company/Terminals.jsx';
import TripMonitor from './pages/company/TripMonitor.jsx';
import UserPermissions from './pages/company/UserPermissions.jsx';
import VoidSecurity from './pages/company/VoidSecurity.jsx';
import Companies from './pages/superadmin/Companies.jsx';
import LegalDocuments from './pages/superadmin/LegalDocuments.jsx';
import PlatformUsers from './pages/superadmin/PlatformUsers.jsx';
import SystemConfiguration from './pages/superadmin/SystemConfiguration.jsx';

/**
 * Blocks the whole app behind a forced password change for a brand-new
 * company admin.
 */
function PasswordGate({ children }) {
    const { mustChangePassword } = useAuth();
    return mustChangePassword ? <ForcePasswordChange /> : children;
}

/**
 * Top-level SPA component: auth context + client-side routing.
 */
export default function Root() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    <Route path="/login" element={<Login />} />
                    <Route path="/legal/privacy-policy" element={<LegalDocumentPage type="privacy_policy" />} />
                    <Route path="/legal/terms-of-use" element={<LegalDocumentPage type="terms_of_use" />} />

                    {/* Thermal receipt print view — no app chrome, opens in its own tab */}
                    <Route
                        path="/receipt"
                        element={(
                            <ProtectedRoute>
                                <ReceiptView />
                            </ProtectedRoute>
                        )}
                    />

                    <Route
                        element={
                            <ProtectedRoute>
                                <LegalConsentGate>
                                    <PasswordGate>
                                        <AppLayout />
                                    </PasswordGate>
                                </LegalConsentGate>
                            </ProtectedRoute>
                        }
                    >
                        <Route index element={<Dashboard />} />
                        <Route path="profile" element={<MyProfile />} />

                        {/* Conductor */}
                        <Route path="conductor/trips" element={<ProtectedRoute permission="trips.view"><ConductorTrip /></ProtectedRoute>} />
                        <Route path="company/live" element={<ProtectedRoute permission="tracking.view"><LiveMonitor /></ProtectedRoute>} />
                        <Route path="company/trip-monitor" element={<ProtectedRoute permission="tripmonitoring.view"><TripMonitor /></ProtectedRoute>} />
                        <Route path="company/attendance" element={<ProtectedRoute permission="attendance.view"><Attendance /></ProtectedRoute>} />
                        <Route path="company/cash-counts" element={<ProtectedRoute permission="cashcount.view"><CashCounts /></ProtectedRoute>} />
                        <Route path="company/expenses" element={<ProtectedRoute permission="expenses.view"><Expenses /></ProtectedRoute>} />
                        <Route path="company/fuel" element={<ProtectedRoute permission="fuel.view"><FuelEnergy /></ProtectedRoute>} />
                        <Route path="company/remittances" element={<ProtectedRoute permission="remittances.view"><Remittances /></ProtectedRoute>} />

                        <Route path="company/reports/income" element={<ProtectedRoute permission="reports.view"><IncomeMonitoring /></ProtectedRoute>} />
                        <Route path="company/reports/daily-operations" element={<ProtectedRoute permission="dailyops.view"><DailyOperations /></ProtectedRoute>} />
                        <Route path="company/reports/expenses" element={<ProtectedRoute permission="expensereport.view"><ExpenseReport /></ProtectedRoute>} />
                        <Route path="company/reports/cash-count" element={<ProtectedRoute permission="cashcountreport.view"><CashCountReport /></ProtectedRoute>} />
                        <Route path="company/reports/fuel-energy" element={<ProtectedRoute permission="fuelenergyreport.view"><FuelEnergyReport /></ProtectedRoute>} />

                        {/* Platform (Super Admin) */}
                        <Route path="companies" element={<ProtectedRoute permission="companies.view"><Companies /></ProtectedRoute>} />
                        <Route path="platform-users" element={<ProtectedRoute permission="platform.users.view"><PlatformUsers /></ProtectedRoute>} />
                        <Route path="super-admin/system-configuration" element={<ProtectedRoute permission="system.configuration.view"><SystemConfiguration /></ProtectedRoute>} />
                        <Route path="super-admin/legal-documents" element={<ProtectedRoute permission="legal.view"><LegalDocuments /></ProtectedRoute>} />

                        {/* Fleet */}
                        <Route path="company/terminals" element={<ProtectedRoute permission="terminals.view"><Terminals /></ProtectedRoute>} />
                        <Route path="company/drivers" element={<ProtectedRoute permission="drivers.view"><Drivers /></ProtectedRoute>} />
                        <Route path="company/passenger-types" element={<ProtectedRoute permission="passengertypes.view"><PassengerTypes /></ProtectedRoute>} />
                        <Route path="company/franchises" element={<ProtectedRoute permission="franchises.view"><Franchises /></ProtectedRoute>} />
                        <Route path="company/fare-matrix/:franchiseId" element={<ProtectedRoute permission="farematrix.view"><FareMatrixGrid /></ProtectedRoute>} />
                        <Route path="company/buses" element={<ProtectedRoute permission="buses.view"><Buses /></ProtectedRoute>} />
                        <Route path="company/thermal-printers" element={<ProtectedRoute permission="thermalprinters.view"><ThermalPrinters /></ProtectedRoute>} />
                        <Route path="company/admin-assignments" element={<ProtectedRoute permission="adminassignments.view"><AdminAssignments /></ProtectedRoute>} />

                        {/* Company */}
                        <Route path="company/profile" element={<ProtectedRoute permission="company.profile.view"><CompanyProfile /></ProtectedRoute>} />
                        <Route path="company/settings" element={<ProtectedRoute permission="company.settings.view"><CompanySettings /></ProtectedRoute>} />
                        <Route path="company/devices" element={<ProtectedRoute permission="devices.view"><Devices /></ProtectedRoute>} />
                        <Route path="company/mobile-app" element={<ProtectedRoute permission="mobileapp.view"><MobileApp /></ProtectedRoute>} />
                        <Route path="company/data-tools" element={<ProtectedRoute permission="backup.view"><DataTools /></ProtectedRoute>} />
                        <Route path="company/audit-log" element={<ProtectedRoute permission="audit.view"><AuditLog /></ProtectedRoute>} />
                        <Route path="company/users" element={<ProtectedRoute permission="accounts.view"><CompanyUsers /></ProtectedRoute>} />
                        <Route path="company/users/:id/permissions" element={<ProtectedRoute permission="permissions.view"><UserPermissions /></ProtectedRoute>} />
                        <Route path="company/roles" element={<ProtectedRoute permission="roles.view"><Roles /></ProtectedRoute>} />
                        <Route path="company/void-security" element={<ProtectedRoute permission="voidsecurity.view"><VoidSecurity /></ProtectedRoute>} />

                        <Route path="*" element={<NotFound />} />
                    </Route>

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    );
}
