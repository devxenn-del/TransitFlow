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
import Buses from './pages/company/Buses.jsx';
import ThermalPrinters from './pages/company/ThermalPrinters.jsx';
import CompanyProfile from './pages/company/CompanyProfile.jsx';
import CompanySettings from './pages/company/CompanySettings.jsx';
import Devices from './pages/company/Devices.jsx';
import MobileApp from './pages/company/MobileApp.jsx';
import CompanyUsers from './pages/company/CompanyUsers.jsx';
import DataTools from './pages/company/DataTools.jsx';
import AuditLog from './pages/company/AuditLog.jsx';
import FuelEnergy from './pages/company/FuelEnergy.jsx';
import LiveMonitor from './pages/company/LiveMonitor.jsx';
import ConductorTrip from './pages/conductor/ConductorTrip.jsx';
import Drivers from './pages/company/Drivers.jsx';
import Conductors from './pages/company/Conductors.jsx';
import FareMatrixGrid from './pages/company/FareMatrixGrid.jsx';
import Franchises from './pages/company/Franchises.jsx';
import PassengerTypes from './pages/company/PassengerTypes.jsx';
import FuelEnergyReport from './pages/company/reports/FuelEnergyReport.jsx';
import IncomeMonitoring from './pages/company/reports/IncomeMonitoring.jsx';
import Roles from './pages/company/Roles.jsx';
import RoutesPage from './pages/company/Routes.jsx';
import Terminals from './pages/company/Terminals.jsx';
import TripMonitor from './pages/company/TripMonitor.jsx';
import UserPermissions from './pages/company/UserPermissions.jsx';
import Companies from './pages/superadmin/Companies.jsx';
import CompanyOverview from './pages/companies/CompanyOverview.jsx';
import CompanyWorkspace from './pages/companies/CompanyWorkspace.jsx';
import CompanyDocuments from './pages/company/CompanyDocuments.jsx';
import Billing from './pages/company/Billing.jsx';
import PricingConfiguration from './pages/company/PricingConfiguration.jsx';
import Fees from './pages/superadmin/Fees.jsx';
import CompanyTickets from './pages/company/CompanyTickets.jsx';
import UserDetails from './pages/company/UserDetails.jsx';
import LegalDocuments from './pages/superadmin/LegalDocuments.jsx';
import SuperAdminMobileApp from './pages/superadmin/MobileApp.jsx';
import PlatformUsers from './pages/superadmin/PlatformUsers.jsx';
import SystemConfiguration from './pages/superadmin/SystemConfiguration.jsx';

/**
 * Every company module page as `[path, permission(s), element]` — rendered
 * under both `/company` and `/companies/:companyId` (see companyRoutes()).
 * Keep in step with lib/companyModules.js, which drives the navigation.
 */
const COMPANY_PAGES = [
    ['live', 'tracking.view', <LiveMonitor />],
    ['trip-monitor', 'tripmonitoring.view', <TripMonitor />],
    ['tickets', 'tripmonitoring.view', <CompanyTickets />],
    ['attendance', 'attendance.view', <Attendance />],
    ['fuel', 'fuel.view', <FuelEnergy />],
    ['buses', 'buses.view', <Buses />],
    ['drivers', 'drivers.view', <Drivers />],
    ['conductors', 'conductors.view', <Conductors />],
    ['terminals', 'terminals.view', <Terminals />],
    ['thermal-printers', 'thermalprinters.view', <ThermalPrinters />],
    ['franchises', 'franchises.view', <Franchises />],
    ['fare-matrix/:franchiseId', 'farematrix.view', <FareMatrixGrid />],
    ['routes', 'routes.view', <RoutesPage />],
    ['passenger-types', 'passengertypes.view', <PassengerTypes />],
    ['reports/income', 'reports.view', <IncomeMonitoring />],
    ['reports/fuel-energy', 'fuelenergyreport.view', <FuelEnergyReport />],
    ['audit-log', 'audit.view', <AuditLog />],
    ['users', 'accounts.view', <CompanyUsers />],
    ['users/:userId', 'accounts.view', <UserDetails />],
    ['users/:id/permissions', 'permissions.view', <UserPermissions />],
    ['roles', 'roles.view', <Roles />],
    ['profile', 'company.profile.view', <CompanyProfile />],
    ['documents', 'documents.view', <CompanyDocuments />],
    ['billing', 'billing.view', <Billing />],
    ['pricing', 'fees.view', <PricingConfiguration />],
    ['settings', ['company.settings.view', 'company.settings.manage'], <CompanySettings />],
    ['devices', 'devices.view', <Devices />],
    ['mobile-app', 'mobileapp.view', <MobileApp />],
    ['data-tools', ['backup.view', 'backup.download', 'cleandata.run'], <DataTools />],
];

function companyRoutes() {
    return COMPANY_PAGES.map(([path, permission, element]) => (
        <Route key={path} path={path} element={<ProtectedRoute permission={permission}>{element}</ProtectedRoute>} />
    ));
}

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

                        {/* Platform (Super Admin) */}
                        <Route path="companies" element={<ProtectedRoute permission="companies.view"><Companies /></ProtectedRoute>} />
                        <Route path="super-admin/fees" element={<ProtectedRoute permission="fees.view"><Fees /></ProtectedRoute>} />
                        <Route path="platform-users" element={<ProtectedRoute permission="platform.users.view"><PlatformUsers /></ProtectedRoute>} />
                        <Route path="super-admin/system-configuration" element={<ProtectedRoute permission="system.configuration.view"><SystemConfiguration /></ProtectedRoute>} />
                        <Route path="super-admin/legal-documents" element={<ProtectedRoute permission="legal.view"><LegalDocuments /></ProtectedRoute>} />
                        <Route path="super-admin/mobile-app" element={<ProtectedRoute permission="mobileapp.manage"><SuperAdminMobileApp /></ProtectedRoute>} />

                        {/* A company user's own company: /company/<module> */}
                        <Route path="company">{companyRoutes()}</Route>

                        {/* Company workspace — the selected company as the container for its modules.
                            Super Admin: any company; a company user: their own (enforced by the API).
                            Same modules, same paths as /company/*. */}
                        <Route path="companies/:companyId" element={<ProtectedRoute permission="company.profile.view"><CompanyWorkspace /></ProtectedRoute>}>
                            <Route index element={<CompanyOverview />} />
                            {companyRoutes()}
                            {/* Earlier workspace tab links */}
                            <Route path="trips" element={<Navigate to="../trip-monitor" replace />} />
                            <Route path="fare-matrix" element={<Navigate to="../franchises" replace />} />
                            <Route path="reports" element={<Navigate to="../reports/income" replace />} />
                            <Route path="franchise-documents" element={<Navigate to="../documents" replace />} />
                        </Route>

                        <Route path="*" element={<NotFound />} />
                    </Route>

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    );
}
