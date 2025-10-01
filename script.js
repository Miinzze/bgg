// Objekt-Verwaltungssystem mit RBAC und Wartungsmanagement
'use strict';

// =============================================================================
// GLOBAL VARIABLES
// =============================================================================

let isDragging = false;
let currentDragElement = null;
let dragOffset = { x: 0, y: 0 };
let isAddingObject = false;
let currentInfoPanel = null;
let currentObjectData = null;
let statusModal = null;
let editUserModal = null;
let currentSettings = null;

let dragStartPosition = null;
let hasMoved = false;
let isEditingPositions = false;

let searchTimeout = null;
let currentSearchResults = [];
let highlightedMarkers = new Set();
let availableRoles = [];

// NEU: Wartungsvariablen
let maintenanceCheckInterval = null;
let currentMaintenanceHistoryObjectId = null;

// =============================================================================
// INITIALIZATION
// =============================================================================

document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
    initializeDashboard();
});

function initializeApp() {
    console.log('Initializing Object Management System with Maintenance...');
    
    setupEventListeners();
    setupDragAndDrop();
    setupKeyboardShortcuts();
    setupSearchFunctionality();
    setupMaintenanceFeatures();
    updateLegend();
    loadAndApplySettings();
    initializeMarkerPositions();
    
    if (window.systemConfig.isLoggedIn && window.systemConfig.permissions.canViewUsers) {
        loadAvailableRoles();
    }
    
    if (window.systemConfig.isLoggedIn && !window.systemConfig.hasBackground && window.systemConfig.permissions.canEditSettings) {
        setTimeout(() => {
            showNotification('Willkommen! Laden Sie zuerst ein Hintergrundbild in den Einstellungen hoch.', 'info', 5000);
        }, 1000);
    }
    
    console.log('App initialized successfully with Maintenance Management');
}

// =============================================================================
// WARTUNGSFUNKTIONEN
// =============================================================================

function setupMaintenanceFeatures() {
    if (!window.systemConfig.isLoggedIn) return;
    
    // Automatische Wartungsprüfung alle 5 Minuten
    maintenanceCheckInterval = setInterval(checkMaintenanceDue, 5 * 60 * 1000);
    
    // Initiale Prüfung
    setTimeout(checkMaintenanceDue, 2000);
    
    // Event-Listener für Wartungsintervall-Felder
    const lastMaintenanceAdd = document.getElementById('obj-last-maintenance');
    const maintenanceIntervalCustomAdd = document.getElementById('obj-maintenance-interval-custom');
    
    if (lastMaintenanceAdd) {
        lastMaintenanceAdd.addEventListener('change', function() {
            updateNextMaintenanceDate('add');
        });
    }
    
    if (maintenanceIntervalCustomAdd) {
        maintenanceIntervalCustomAdd.addEventListener('input', function() {
            updateNextMaintenanceDate('add');
        });
    }
    
    const lastMaintenanceEdit = document.getElementById('edit-obj-last-maintenance');
    const maintenanceIntervalCustomEdit = document.getElementById('edit-obj-maintenance-interval-custom');
    
    if (lastMaintenanceEdit) {
        lastMaintenanceEdit.addEventListener('change', function() {
            updateNextMaintenanceDate('edit');
        });
    }
    
    if (maintenanceIntervalCustomEdit) {
        maintenanceIntervalCustomEdit.addEventListener('input', function() {
            updateNextMaintenanceDate('edit');
        });
    }
}

// =============================================================================
// LAGERGERÄTE-FUNKTIONEN
// =============================================================================

function toggleStorageDevice(mode) {
    const checkbox = document.getElementById(mode === 'add' ? 'obj-is-storage' : 'edit-obj-is-storage');
    const maintenanceSection = checkbox.closest('form').querySelector('.maintenance-section');
    
    if (checkbox.checked) {
        // Wartungsfelder deaktivieren
        maintenanceSection.style.opacity = '0.5';
        maintenanceSection.style.pointerEvents = 'none';
        
        const lastMaintenanceInput = document.getElementById(`${mode === 'add' ? 'obj' : 'edit-obj'}-last-maintenance`);
        const intervalSelect = document.getElementById(`${mode === 'add' ? 'obj' : 'edit-obj'}-maintenance-interval`);
        const customIntervalInput = document.getElementById(`${mode === 'add' ? 'obj' : 'edit-obj'}-maintenance-interval-custom`);
        
        if (lastMaintenanceInput) lastMaintenanceInput.value = '';
        if (intervalSelect) intervalSelect.value = '';
        if (customIntervalInput) customIntervalInput.value = '';
        
        document.getElementById(`custom-interval-${mode}`).style.display = 'none';
        document.getElementById(`next-maintenance-info-${mode}`).style.display = 'none';
        
        showNotification('Lagergeräte benötigen keine Wartungsinformationen', 'info', 3000);
    } else {
        // Wartungsfelder aktivieren
        maintenanceSection.style.opacity = '1';
        maintenanceSection.style.pointerEvents = 'auto';
    }
}

function handleMaintenanceIntervalChange(selectElement, mode) {
    const customField = document.getElementById(`custom-interval-${mode}`);
    const customInput = document.getElementById(`${mode === 'add' ? 'obj' : 'edit-obj'}-maintenance-interval-custom`);
    
    if (selectElement.value === 'custom') {
        customField.style.display = 'block';
        if (customInput) customInput.required = true;
    } else {
        customField.style.display = 'none';
        if (customInput) {
            customInput.required = false;
            if (selectElement.value !== '') {
                customInput.value = selectElement.value;
            } else {
                customInput.value = '';
            }
        }
    }
    
    updateNextMaintenanceDate(mode);
}

function updateNextMaintenanceDate(mode) {
    const prefix = mode === 'add' ? 'obj' : 'edit-obj';
    const lastMaintenanceInput = document.getElementById(`${prefix}-last-maintenance`);
    const intervalSelect = document.getElementById(`${prefix}-maintenance-interval`);
    const customIntervalInput = document.getElementById(`${prefix}-maintenance-interval-custom`);
    const infoBox = document.getElementById(`next-maintenance-info-${mode}`);
    const dateDisplay = document.getElementById(`next-maintenance-date-${mode}`);
    
    if (!lastMaintenanceInput || !intervalSelect || !infoBox || !dateDisplay) return;
    
    const lastMaintenance = lastMaintenanceInput.value;
    let intervalDays = null;
    
    if (intervalSelect.value === 'custom') {
        intervalDays = customIntervalInput ? parseInt(customIntervalInput.value) : null;
    } else if (intervalSelect.value !== '') {
        intervalDays = parseInt(intervalSelect.value);
    }
    
    if (lastMaintenance && intervalDays && intervalDays > 0) {
        const nextDate = calculateNextMaintenanceDate(lastMaintenance, intervalDays);
        if (nextDate) {
            dateDisplay.textContent = formatDate(nextDate);
            infoBox.style.display = 'block';
            
            // Prüfe ob überfällig
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const nextDateObj = new Date(nextDate);
            nextDateObj.setHours(0, 0, 0, 0);
            
            if (nextDateObj <= today) {
                infoBox.classList.add('maintenance-overdue');
                dateDisplay.textContent += ' (ÜBERFÄLLIG!)';
            } else {
                infoBox.classList.remove('maintenance-overdue');
            }
        } else {
            infoBox.style.display = 'none';
        }
    } else {
        infoBox.style.display = 'none';
    }
}

function calculateNextMaintenanceDate(lastMaintenanceDate, intervalDays) {
    try {
        const date = new Date(lastMaintenanceDate);
        if (isNaN(date.getTime())) return null;
        
        date.setDate(date.getDate() + parseInt(intervalDays));
        return date.toISOString().split('T')[0];
    } catch (e) {
        return null;
    }
}

async function checkMaintenanceDue() {
    if (!window.systemConfig.isLoggedIn) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_maintenance');
        
        const response = await fetch('maintenance_check.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.notifications && result.notifications.length > 0) {
                result.notifications.forEach(notification => {
                    let icon = '';
                    let type = 'info';
                    
                    switch(notification.type) {
                        case 'maintenance_set':
                            icon = '🔧';
                            type = 'warning';
                            break;
                        case 'waiting':
                            icon = '⏳';
                            type = 'info';
                            break;
                        case 'maintenance_after_rental':
                            icon = '🔧';
                            type = 'warning';
                            break;
                    }
                    
                    showNotification(
                        `${icon} ${notification.title}: ${notification.message}`,
                        type,
                        6000
                    );
                });
            }
            
            // Aktualisiere Marker wenn welche geändert wurden
            if (result.updated && result.updated.length > 0) {
                setTimeout(() => location.reload(), 2000);
            }
        }
    } catch (error) {
        console.error('Maintenance check error:', error);
    }
}

async function showMaintenanceHistory() {
    const objectId = document.getElementById('edit-object-id').value;
    if (!objectId) return;
    
    const displayDiv = document.getElementById('maintenance-history-display');
    if (!displayDiv) return;
    
    try {
        displayDiv.innerHTML = '<div style="text-align: center; padding: 1rem; color: #666;">Lade Wartungshistorie...</div>';
        displayDiv.style.display = 'block';
        
        const formData = new FormData();
        formData.append('action', 'get_maintenance_history');
        formData.append('object_id', objectId);
        
        const response = await fetch('maintenance_check.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.history && result.history.length > 0) {
                displayDiv.innerHTML = `
                    <div class="maintenance-history-list">
                        <h4 style="margin-bottom: 0.5rem; color: var(--primary-color);">Wartungshistorie</h4>
                        ${result.history.map(entry => `
                            <div class="maintenance-history-entry">
                                <div class="maintenance-history-date">
                                    📅 ${formatDate(entry.maintenance_date)}
                                    ${entry.was_automatic ? '<span class="auto-badge">Automatisch</span>' : ''}
                                </div>
                                ${entry.performed_by_name ? `
                                    <div class="maintenance-history-user">
                                        👤 Durchgeführt von: ${escapeHtml(entry.performed_by_name)}
                                    </div>
                                ` : ''}
                                ${entry.notes ? `
                                    <div class="maintenance-history-notes">
                                        📝 ${escapeHtml(entry.notes)}
                                    </div>
                                ` : ''}
                            </div>
                        `).join('')}
                    </div>
                `;
            } else {
                displayDiv.innerHTML = '<div style="text-align: center; padding: 1rem; color: #666; font-style: italic;">Keine Wartungshistorie vorhanden</div>';
            }
        } else {
            displayDiv.innerHTML = `<div style="text-align: center; padding: 1rem; color: #dc3545;">Fehler: ${result.message || 'Konnte Historie nicht laden'}</div>`;
        }
    } catch (error) {
        console.error('Error loading maintenance history:', error);
        displayDiv.innerHTML = '<div style="text-align: center; padding: 1rem; color: #dc3545;">Verbindungsfehler</div>';
    }
}

function formatMaintenanceInterval(days) {
    if (!days) return 'Nicht festgelegt';
    
    days = parseInt(days);
    
    if (days % 365 === 0) {
        const years = days / 365;
        return years + (years === 1 ? ' Jahr' : ' Jahre');
    } else if (days % 30 === 0) {
        const months = days / 30;
        return months + (months === 1 ? ' Monat' : ' Monate');
    } else if (days % 7 === 0) {
        const weeks = days / 7;
        return weeks + (weeks === 1 ? ' Woche' : ' Wochen');
    } else {
        return days + ' Tage';
    }
}

// =============================================================================
// BEARBEITUNGSMODUS TOGGLE
// =============================================================================

function setupEditModeToggle() {
    if (!window.systemConfig || !window.systemConfig.permissions.canEditMarker) return;
    
    const toggleBtn = document.getElementById('toggle-edit-mode-btn');
    if (!toggleBtn) return;
    
    toggleBtn.addEventListener('click', toggleEditMode);
    updateEditModeUI();
}

function toggleEditMode() {
    isEditingPositions = !isEditingPositions;
    updateEditModeUI();
    
    if (isEditingPositions) {
        showNotification('Positionsbearbeitung aktiviert - Sie können jetzt Marker verschieben', 'info', 3000);
    } else {
        showNotification('Positionsbearbeitung deaktiviert - Marker sind gesperrt', 'success', 3000);
    }
    
    if (!isEditingPositions) {
        disableAddObjectMode();
    }
}

function updateEditModeUI() {
    const toggleBtn = document.getElementById('toggle-edit-mode-btn');
    const editModeText = document.getElementById('edit-mode-text');
    const container = document.getElementById('image-map-container');
    const backgroundImage = document.getElementById('background-image');
    
    if (!toggleBtn || !editModeText) return;
    
    if (isEditingPositions) {
        toggleBtn.classList.remove('btn-secondary');
        toggleBtn.classList.add('btn-primary');
        toggleBtn.querySelector('.btn-icon').textContent = '🔓';
        editModeText.textContent = 'Bearbeitung aktiv';
        
        if (container) container.classList.add('edit-mode-active');
        if (backgroundImage) backgroundImage.style.cursor = 'default';
        
        document.querySelectorAll('.map-object').forEach(marker => {
            marker.style.cursor = 'move';
            marker.classList.add('editable');
        });
    } else {
        toggleBtn.classList.remove('btn-primary');
        toggleBtn.classList.add('btn-secondary');
        toggleBtn.querySelector('.btn-icon').textContent = '🔒';
        editModeText.textContent = 'Positionen gesperrt';
        
        if (container) container.classList.remove('edit-mode-active');
        if (backgroundImage) backgroundImage.style.cursor = 'default';
        
        document.querySelectorAll('.map-object').forEach(marker => {
            marker.style.cursor = 'pointer';
            marker.classList.remove('editable');
        });
    }
}

// =============================================================================
// PROZENTUALE POSITIONIERUNG
// =============================================================================

function initializeMarkerPositions() {
    const backgroundImage = document.getElementById('background-image');
    if (!backgroundImage) return;
    
    updateAllMarkerPositions();
    window.addEventListener('resize', debounce(updateAllMarkerPositions, 100));
}

function updateAllMarkerPositions() {
    const markers = document.querySelectorAll('.map-object');
    markers.forEach(marker => {
        const xPercent = parseFloat(marker.dataset.xPercent) || 50;
        const yPercent = parseFloat(marker.dataset.yPercent) || 50;
        updateMarkerPosition(marker, xPercent, yPercent);
    });
}

function updateMarkerPosition(marker, xPercent, yPercent) {
    if (!marker) return;
    marker.style.left = xPercent + '%';
    marker.style.top = yPercent + '%';
    marker.dataset.xPercent = xPercent;
    marker.dataset.yPercent = yPercent;
}

function getPercentFromPixel(pixelX, pixelY, containerWidth, containerHeight) {
    const xPercent = Math.max(0, Math.min(100, (pixelX / containerWidth) * 100));
    const yPercent = Math.max(0, Math.min(100, (pixelY / containerHeight) * 100));
    return { xPercent, yPercent };
}

// =============================================================================
// EVENT LISTENERS SETUP
// =============================================================================

function setupEventListeners() {
    setupAuthListeners();
    
    if (window.systemConfig.permissions.canEditMarker || window.systemConfig.permissions.canCreateMarker) {
        setupAdminListeners();
    }
    
    if (window.systemConfig.permissions.canEditMarker) {
        setupEditModeToggle();
    }
    
    setupModalListeners();
    setupObjectListeners();
    setupFormListeners();
    setupWindowListeners();
}

function setupAuthListeners() {
    const loginBtn = document.getElementById('login-btn');
    const logoutBtn = document.getElementById('logout-btn');
    
    if (loginBtn) loginBtn.addEventListener('click', showLoginModal);
    if (logoutBtn) logoutBtn.addEventListener('click', handleLogout);
}

function setupAdminListeners() {
    const addObjectBtn = document.getElementById('add-object-btn');
    const manageUsersBtn = document.getElementById('manage-users-btn');
    
    if (addObjectBtn && window.systemConfig.permissions.canCreateMarker) {
        addObjectBtn.addEventListener('click', enableAddObjectMode);
    }
    
    if (manageUsersBtn && window.systemConfig.permissions.canViewUsers) {
        manageUsersBtn.addEventListener('click', showUsersModal);
    }
    
    if (window.systemConfig.permissions.canCreateMarker) {
        const backgroundImage = document.getElementById('background-image');
        if (backgroundImage) {
            backgroundImage.addEventListener('click', handleBackgroundClick);
        }
    }
}

function setupModalListeners() {
    document.addEventListener('click', function(e) {
        if (!e || !e.target) return;
        
        if (e.target.classList.contains('modal') || e.target.classList.contains('close-modal')) {
            hideAllModals();
            disableAddObjectMode();
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (!e) return;
        
        if (e.key === 'Escape') {
            const searchPanel = document.getElementById('search-panel');
            if (searchPanel && searchPanel.classList.contains('active')) {
                closeSearchPanel();
            } else {
                hideAllModals();
                disableAddObjectMode();
                hideInfoPanel();
                hideStatusModal();
                hideEditUserModal();
            }
        }
    });
}

function setupObjectListeners() {
    document.addEventListener('click', function(e) {
        if (!e || !e.target) return;
        
        try {
            if (e.target.classList && e.target.classList.contains('close-info-panel')) {
                e.preventDefault();
                e.stopPropagation();
                hideInfoPanel();
                return;
            }
            
            if (e.target.classList && e.target.classList.contains('edit-status-btn')) {
                e.preventDefault();
                e.stopPropagation();
                showStatusModal(e.target);
                return;
            }
            
            if (e.target.classList && e.target.classList.contains('edit-btn')) {
                e.preventDefault();
                e.stopPropagation();
                handleEditClick(e.target);
                return;
            }
            
            if (e.target.classList && e.target.classList.contains('delete-btn')) {
                e.preventDefault();
                e.stopPropagation();
                handleDelete(e.target);
                return;
            }
            
            if (e.target.classList && e.target.classList.contains('map-object')) {
                e.preventDefault();
                e.stopPropagation();
                showInfoPanel(e.target);
                return;
            }
        } catch (error) {
            console.error('Error in click handler:', error);
        }
    });
    
    document.addEventListener('click', function(e) {
        if (!e || !e.target) return;
        
        const infoPanel = currentInfoPanel;
        
        if (infoPanel && 
            !infoPanel.contains(e.target) && 
            !e.target.classList.contains('map-object') &&
            !e.target.closest('.result-btn') &&
            !e.target.closest('.modal')) {
            hideInfoPanel();
        }
    });
}

function setupFormListeners() {
    const forms = [
        { id: 'login-form', handler: handleLogin },
        { id: 'add-object-form', handler: handleAddObject },
        { id: 'edit-object-form', handler: handleEditObject },
        { id: 'add-user-form', handler: handleAddUser }
    ];
    
    forms.forEach(({ id, handler }) => {
        const form = document.getElementById(id);
        if (form) form.addEventListener('submit', handler);
    });
}

function setupWindowListeners() {
    window.addEventListener('resize', debounce(() => {
        hideInfoPanel();
        hideStatusModal();
    }, 250));
}

function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        if (!e || (e.target && e.target.matches && e.target.matches('input, textarea, select'))) return;
        
        if ((e.ctrlKey || e.metaKey) && e.key === 'f' && window.systemConfig.isLoggedIn) {
            e.preventDefault();
            openSearchPanel();
            return;
        }
        
        if (e.key === 'Escape') {
            const searchPanel = document.getElementById('search-panel');
            if (searchPanel && searchPanel.classList.contains('active')) {
                closeSearchPanel();
            } else {
                hideAllModals();
                disableAddObjectMode();
                hideInfoPanel();
                hideStatusModal();
                hideEditUserModal();
            }
        }
    });
}

// =============================================================================
// AUTHENTICATION FUNCTIONS
// =============================================================================

async function handleLogin(e) {
    if (!e) return;
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'login');
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : '';
    
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Anmelden...';
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Erfolgreich angemeldet', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(result.message || 'Anmeldung fehlgeschlagen', 'error');
        }
    } catch (error) {
        console.error('Login error:', error);
        showNotification('Verbindungsfehler beim Anmelden', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }
}

async function handleLogout() {
    if (!confirm('Möchten Sie sich wirklich abmelden?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'logout');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Erfolgreich abgemeldet', 'success');
            // ÄNDERUNG: Redirect zur Hauptseite statt reload
            setTimeout(() => window.location.href = 'index.php', 1000);
        } else {
            showNotification('Fehler beim Abmelden', 'error');
        }
    } catch (error) {
        console.error('Logout error:', error);
        showNotification('Verbindungsfehler beim Abmelden', 'error');
        // Auch bei Fehler zur Hauptseite redirecten
        setTimeout(() => window.location.href = 'index.php', 1000);
    }
}

// =============================================================================
// FORMULAR HANDLER MIT WARTUNG
// =============================================================================

async function handleAddObject(e) {
    if (!e) return;
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'add_object');
    
    const xPercent = form.dataset.xPercent || 50;
    const yPercent = form.dataset.yPercent || 50;
    formData.append('x_percent', xPercent);
    formData.append('y_percent', yPercent);
    
    const title = formData.get('title');
    if (!title || title.trim() === '') {
        showNotification('Titel ist erforderlich', 'warning');
        return;
    }
    
    // Wartungsintervall verarbeiten
    const intervalSelect = document.getElementById('obj-maintenance-interval');
    const customIntervalInput = document.getElementById('obj-maintenance-interval-custom');
    
    if (intervalSelect && intervalSelect.value !== '') {
        if (intervalSelect.value === 'custom') {
            if (!customIntervalInput || !customIntervalInput.value) {
                showNotification('Bitte geben Sie ein benutzerdefiniertes Wartungsintervall ein', 'warning');
                return;
            }
        }
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : '';
    
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Hinzufügen...';
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            hideAllModals();
            showNotification('Marker erfolgreich hinzugefügt', 'success');
            
            const category = formData.get('category') || 'general';
            const lastMaintenance = formData.get('last_maintenance');
            const maintenanceInterval = formData.get('maintenance_interval');
            
			const newMarker = createObjectElement({
				id: result.id,
				title: title,
				category: category,
				xPercent: parseFloat(xPercent),
				yPercent: parseFloat(yPercent),
				status: 'available',
				last_maintenance: lastMaintenance,
				maintenance_interval_days: maintenanceInterval,
				is_storage_device: formData.get('is_storage_device') ? 1 : 0
			});
            
			if (formData.get('is_storage_device')) {
				newMarker.style.background = window.systemConfig.storageDeviceColor;
				newMarker.style.borderColor = '#757575';
			}
			
            const backgroundImage = document.getElementById('background-image');
            if (backgroundImage) {
                backgroundImage.appendChild(newMarker);
                setupDragAndDropForElement(newMarker);
                newMarker.classList.add('newly-added');
                
                if (isEditingPositions) {
                    newMarker.style.cursor = 'move';
                    newMarker.classList.add('editable');
                } else {
                    newMarker.style.cursor = 'pointer';
                }
            }
            
            form.reset();
            delete form.dataset.xPercent;
            delete form.dataset.yPercent;
            
            // Reset Wartungsfelder
            document.getElementById('custom-interval-add').style.display = 'none';
            document.getElementById('next-maintenance-info-add').style.display = 'none';
            
            updateLegend();
            
            const searchPanel = document.getElementById('search-panel');
            if (searchPanel && searchPanel.classList.contains('active')) {
                performSearch();
            }
        } else {
            showNotification(result.message || 'Fehler beim Hinzufügen', 'error');
        }
    } catch (error) {
        console.error('Add object error:', error);
        showNotification('Verbindungsfehler beim Hinzufügen', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }
}

async function handleEditObject(e) {
    if (!e) return;
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'update_object');
    
    const objectId = formData.get('id');
    const title = formData.get('title');
    
    if (!objectId) {
        showNotification('Objekt-ID fehlt', 'error');
        return;
    }
    
    if (!title || title.trim() === '') {
        showNotification('Titel ist erforderlich', 'error');
        return;
    }
    
    // Wartungsintervall verarbeiten
    const intervalSelect = document.getElementById('edit-obj-maintenance-interval');
    const customIntervalInput = document.getElementById('edit-obj-maintenance-interval-custom');
    
    if (intervalSelect && intervalSelect.value !== '') {
        if (intervalSelect.value === 'custom') {
            if (!customIntervalInput || !customIntervalInput.value) {
                showNotification('Bitte geben Sie ein benutzerdefiniertes Wartungsintervall ein', 'warning');
                return;
            }
        }
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : '';
    
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Speichern...';
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            hideAllModals();
            
            const markerElement = document.querySelector(`.map-object[data-id="${objectId}"]`);
            if (markerElement) {
                markerElement.dataset.title = title;
                markerElement.dataset.category = formData.get('category') || 'general';
            }
            
            if (currentObjectData && currentObjectData.id == objectId) {
                currentObjectData.title = title;
                currentObjectData.description = formData.get('description') || '';
                currentObjectData.category = formData.get('category') || 'general';
                currentObjectData.last_maintenance = formData.get('last_maintenance');
                currentObjectData.maintenance_interval_days = formData.get('maintenance_interval');
                
                if (currentInfoPanel) {
                    renderInfoPanel(currentInfoPanel, currentObjectData);
                }
            }
            
            const searchPanel = document.getElementById('search-panel');
            if (searchPanel && searchPanel.classList.contains('active')) {
                performSearch();
            }
            
            showNotification('Objekt erfolgreich aktualisiert', 'success');
            form.reset();
        } else {
            showNotification(result.message || 'Fehler beim Aktualisieren', 'error');
        }
    } catch (error) {
        console.error('Error updating object:', error);
        showNotification('Verbindungsfehler beim Speichern', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }
}

async function handleEditClick(button) {
    if (!button || !button.dataset) return;
    
    const objectId = button.dataset.id;
    if (!objectId) return;
    
    try {
        button.style.opacity = '0.6';
        button.textContent = 'Laden...';
        
        const formData = new FormData();
        formData.append('action', 'get_object');
        formData.append('id', objectId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.object) {
            populateEditModal(result.object);
            showEditModal();
        } else {
            showNotification(result.message || 'Fehler beim Laden der Objektdaten', 'error');
        }
    } catch (error) {
        console.error('Error loading object for edit:', error);
        showNotification('Verbindungsfehler beim Laden', 'error');
    } finally {
        button.style.opacity = '';
        button.textContent = 'Bearbeiten';
    }
}

function populateEditModal(objectData) {
    const editIdInput = document.getElementById('edit-object-id');
    const editTitleInput = document.getElementById('edit-obj-title');
    const editCategorySelect = document.getElementById('edit-obj-category');
    const editDescriptionTextarea = document.getElementById('edit-obj-description');
    const currentImagePreview = document.getElementById('current-image-preview');
    
    // Basis-Felder
    if (editIdInput) editIdInput.value = objectData.id;
    if (editTitleInput) editTitleInput.value = objectData.title || '';
    if (editCategorySelect) editCategorySelect.value = objectData.category || 'general';
    if (editDescriptionTextarea) editDescriptionTextarea.value = objectData.description || '';
    
    const storageCheckbox = document.getElementById('edit-obj-is-storage');
    if (storageCheckbox) {
        storageCheckbox.checked = objectData.is_storage_device == 1;
        toggleStorageDevice('edit');
    }

    // Bild-Preview
    if (currentImagePreview) {
        if (objectData.image_path) {
            currentImagePreview.innerHTML = `
                <p style="margin-bottom: 10px; font-weight: 500; color: #555;">Aktuelles Bild:</p>
                <img src="${escapeHtml(objectData.image_path)}" 
                     alt="${escapeHtml(objectData.title)}" 
                     style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 2px solid #ddd;">
                <p style="margin-top: 8px; font-size: 0.85rem; color: #666; font-style: italic;">
                    Wählen Sie ein neues Bild aus, um das aktuelle zu ersetzen
                </p>
            `;
        } else {
            currentImagePreview.innerHTML = `<p style="color: #666; font-style: italic;">Kein Bild vorhanden</p>`;
        }
    }
    
    // WARTUNGSFELDER
    const lastMaintenanceInput = document.getElementById('edit-obj-last-maintenance');
    const maintenanceIntervalSelect = document.getElementById('edit-obj-maintenance-interval');
    const customIntervalInput = document.getElementById('edit-obj-maintenance-interval-custom');
    const customIntervalDiv = document.getElementById('custom-interval-edit');
    
    if (lastMaintenanceInput) {
        lastMaintenanceInput.value = objectData.last_maintenance || '';
    }
    
    if (maintenanceIntervalSelect && customIntervalInput && customIntervalDiv) {
        const intervalDays = objectData.maintenance_interval_days;
        
        if (!intervalDays) {
            maintenanceIntervalSelect.value = '';
            customIntervalDiv.style.display = 'none';
            customIntervalInput.value = '';
        } else {
            // Prüfe ob es ein Standard-Intervall ist
            const standardIntervals = ['7', '14', '30', '60', '90', '180', '365'];
            if (standardIntervals.includes(intervalDays.toString())) {
                maintenanceIntervalSelect.value = intervalDays.toString();
                customIntervalDiv.style.display = 'none';
                customIntervalInput.value = intervalDays;
            } else {
                maintenanceIntervalSelect.value = 'custom';
                customIntervalDiv.style.display = 'block';
                customIntervalInput.value = intervalDays;
            }
        }
    }
    
    // Wartungshistorie zurücksetzen
    const historyDisplay = document.getElementById('maintenance-history-display');
    if (historyDisplay) {
        historyDisplay.style.display = 'none';
        historyDisplay.innerHTML = '';
    }
    
    // Nächstes Wartungsdatum berechnen
    updateNextMaintenanceDate('edit');
}

async function handleDelete(button) {
    if (!button || !button.dataset) return;
    
    const objectId = button.dataset.id;
    if (!objectId) return;
    
    const markerElement = document.querySelector(`.map-object[data-id="${objectId}"]`);
    const objectTitle = (markerElement && markerElement.dataset.title) ? markerElement.dataset.title : 'dieses Objekt';
    
    if (!confirm(`Möchten Sie "${objectTitle}" wirklich löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden.`)) {
        return;
    }
    
    try {
        button.style.opacity = '0.6';
        button.textContent = 'Lösche...';
        
        const formData = new FormData();
        formData.append('action', 'delete_object');
        formData.append('id', objectId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (markerElement) {
                markerElement.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => {
                    if (markerElement.parentNode) {
                        markerElement.parentNode.removeChild(markerElement);
                    }
                }, 300);
            }
            
            if (currentObjectData && currentObjectData.id == objectId) {
                hideInfoPanel();
            }
            
            hideStatusModal();
            
            const searchPanel = document.getElementById('search-panel');
            if (searchPanel && searchPanel.classList.contains('active')) {
                performSearch();
            }
            
            showNotification('Marker erfolgreich gelöscht', 'success');
            updateLegend();
        } else {
            showNotification(result.message || 'Fehler beim Löschen', 'error');
        }
    } catch (error) {
        console.error('Delete error:', error);
        showNotification('Verbindungsfehler beim Löschen', 'error');
    } finally {
        button.style.opacity = '';
        button.textContent = 'Löschen';
    }
}

// =============================================================================
// INFO PANEL MIT WARTUNGSINFORMATIONEN
// =============================================================================

function renderInfoPanel(panel, objectData) {
    if (!panel || !objectData) return;
    
    try {
        const isLoggedIn = window.systemConfig.isLoggedIn;
        const canChangeStatus = window.systemConfig.permissions.canChangeStatus;
        const canEdit = window.systemConfig.permissions.canEditMarker;
        const canDelete = window.systemConfig.permissions.canDeleteMarker;
        
        // NEU: Prüfe ob Lagergerät
        const isStorageDevice = objectData.is_storage_device == 1;
        
        // Lagergeräte haben keinen Status
        const statusOptions = {
            'available': { text: 'Verfügbar', color: '#28a745' },
            'rented': { text: 'Vermietet', color: '#dc3545' },
            'maintenance': { text: 'Wartung', color: '#ffc107' }
        };
        
        // Wartungsinformationen nur wenn KEIN Lagergerät
        let maintenanceHtml = '';
        if (!isStorageDevice && (objectData.last_maintenance || objectData.maintenance_interval_days)) {
            maintenanceHtml = `
                <div style="margin-bottom: 15px; padding: 12px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #6bb032;">
                    <h4 style="margin: 0 0 8px 0; color: #2c5530; font-size: 0.95rem;">🔧 Wartungsinformationen</h4>
                    
                    ${objectData.last_maintenance ? `
                        <div style="margin-bottom: 6px;">
                            <strong>Letzte Wartung:</strong> ${formatDate(objectData.last_maintenance)}
                        </div>
                    ` : ''}
                    
                    ${objectData.maintenance_interval_days ? `
                        <div style="margin-bottom: 6px;">
                            <strong>Intervall:</strong> ${formatMaintenanceInterval(objectData.maintenance_interval_days)}
                        </div>
                    ` : ''}
                    
                    ${objectData.next_maintenance_due ? `
                        <div style="margin-bottom: 6px;">
                            <strong>Nächste Wartung:</strong> 
                            <span style="color: ${objectData.maintenance_overdue ? '#dc3545' : '#28a745'}; font-weight: 600;">
                                ${formatDate(objectData.next_maintenance_due)}
                                ${objectData.maintenance_overdue ? ' (ÜBERFÄLLIG!)' : ''}
                            </span>
                        </div>
                    ` : ''}
                    
                    ${objectData.days_until_maintenance !== undefined && objectData.days_until_maintenance !== null ? `
                        <div style="font-size: 0.85rem; color: #666; margin-top: 4px;">
                            ${objectData.days_until_maintenance > 0 
                                ? `⏳ Noch ${objectData.days_until_maintenance} Tage` 
                                : `⚠️ ${Math.abs(objectData.days_until_maintenance)} Tage überfällig`
                            }
                        </div>
                    ` : ''}
                </div>
            `;
        }
        
        // Status-Sektion - nur wenn KEIN Lagergerät
        let statusHtml = '';
        if (isStorageDevice) {
            statusHtml = `
                <div style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 8px; border-left: 4px solid var(--storage-device-color, #9e9e9e);">
                    <h4 style="margin: 0 0 10px 0; color: #333; font-size: 1rem;">📦 Lagergerät</h4>
                    <p style="margin: 0; color: #666; font-size: 0.9rem;">
                        Dieses Objekt ist als Lagergerät markiert und benötigt keine Wartung.
                    </p>
                </div>
            `;
        } else {
            statusHtml = `
                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0; color: #333; font-size: 1rem;">Status:</h4>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                        <div style="width: 16px; height: 16px; border-radius: 50%; background-color: ${statusOptions[objectData.status].color};"></div>
                        <span style="font-weight: 500; color: ${statusOptions[objectData.status].color}; font-size: 1.1rem;">
                            ${statusOptions[objectData.status].text}
                        </span>
                    </div>
                    
                    ${isLoggedIn ? `
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            ${canChangeStatus ? `
                                <button class="edit-status-btn" data-id="${objectData.id}" data-current-status="${objectData.status}"
                                        style="background: #4a90e2; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                                    Status ändern
                                </button>
                            ` : ''}
                            
                            ${canEdit ? `
                                <button class="edit-btn" data-id="${objectData.id}"
                                        style="background: #17a2b8; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                                    Bearbeiten
                                </button>
                            ` : ''}
                            
                            ${canDelete ? `
                                <button class="delete-btn" data-id="${objectData.id}"
                                        style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                                    Löschen
                                </button>
                            ` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        }
        
        panel.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #2c5530; font-size: 1.3rem;">${escapeHtml(objectData.title)}</h3>
                <button class="close-info-panel" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 5px; border-radius: 50%; transition: all 0.2s;">×</button>
            </div>
            
            <div style="margin-bottom: 15px;">
                ${(() => {
                    const categoryInfo = window.systemConfig.categoriesMap[objectData.category] || {
                        display_name: 'Allgemein',
                        icon: '📦',
                        color: '#6bb032'
                    };
                    return `
                        <span style="background: ${categoryInfo.color}; color: white; padding: 6px 14px; border-radius: 15px; font-size: 0.9rem; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            ${categoryInfo.icon} ${escapeHtml(categoryInfo.display_name)}
                        </span>
                    `;
                })()}
            </div>
            
            ${objectData.image_path ? `
                <div style="margin-bottom: 15px; text-align: center;">
                    <img src="${escapeHtml(objectData.image_path)}" 
                         alt="${escapeHtml(objectData.title)}" 
                         style="max-width: 100%; height: auto; max-height: 200px; border-radius: 8px; border: 2px solid #eee;">
                </div>
            ` : ''}
            
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 8px 0; color: #333; font-size: 1rem;">Beschreibung:</h4>
                <p style="margin: 0; color: #555; line-height: 1.5; font-size: 0.9rem;">
                    ${objectData.description ? nl2br(escapeHtml(objectData.description)) : 'Keine Beschreibung verfügbar'}
                </p>
            </div>
            
            ${maintenanceHtml}
            ${statusHtml}
            
            ${isLoggedIn && isStorageDevice ? `
                <div style="display: flex; gap: 8px; flex-wrap: wrap; padding-top: 15px; border-top: 1px solid #eee;">
                    ${canEdit ? `
                        <button class="edit-btn" data-id="${objectData.id}"
                                style="background: #17a2b8; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Bearbeiten
                        </button>
                    ` : ''}
                    
                    ${canDelete ? `
                        <button class="delete-btn" data-id="${objectData.id}"
                                style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Löschen
                        </button>
                    ` : ''}
                </div>
            ` : ''}
        `;
    } catch (error) {
        console.error('Error rendering info panel:', error);
    }
}

// =============================================================================
// UTILITY FUNCTIONS
// =============================================================================

function formatDate(dateString) {
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('de-DE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });
    } catch {
        return dateString;
    }
}

function showNotification(message, type = 'success', duration = 4000) {
    if (!message) return;
    
    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            max-width: 400px;
        `;
        document.body.appendChild(container);
    }
    
    const notification = document.createElement('div');
    notification.style.cssText = `
        background: white !important;
        color: #333 !important;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 0.5rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideInRight 0.3s ease;
        border-left: 4px solid ${type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#28a745'};
    `;
    
    notification.innerHTML = `
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
            <span style="color: #333 !important; font-weight: 500;">${escapeHtml(message)}</span>
            <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #666;">×</button>
        </div>
    `;
    
    container.appendChild(notification);
    
    if (duration > 0) {
        setTimeout(() => notification.remove(), duration);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function nl2br(text) {
    if (!text) return '';
    return text.replace(/\n/g, '<br>');
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func(...args), wait);
    };
}

console.log('Object Management System with Maintenance fully loaded');

// =============================================================================
// SEARCH FUNCTIONALITY
// =============================================================================

function setupSearchFunctionality() {
    if (!window.systemConfig.isLoggedIn) return;
    
    const searchBtn = document.getElementById('search-btn');
    const closeSearchBtn = document.getElementById('close-search-btn');
    const searchInput = document.getElementById('search-input');
    const clearSearchBtn = document.getElementById('clear-search-btn');
    const statusFilter = document.getElementById('status-filter');
    const categoryFilter = document.getElementById('category-filter');
    const resetSearchBtn = document.getElementById('reset-search-btn');
    
    if (searchBtn) searchBtn.addEventListener('click', toggleSearchPanel);
    if (closeSearchBtn) closeSearchBtn.addEventListener('click', closeSearchPanel);
    
    if (searchInput) {
        searchInput.addEventListener('input', debounce(performSearch, 300));
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSearchPanel();
        });
    }
    
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchInput.focus();
            performSearch();
        });
    }
    
    if (statusFilter) statusFilter.addEventListener('change', performSearch);
    if (categoryFilter) categoryFilter.addEventListener('change', performSearch);
    if (resetSearchBtn) resetSearchBtn.addEventListener('click', resetSearch);
}

function toggleSearchPanel() {
    const panel = document.getElementById('search-panel');
    if (!panel) return;
    
    if (panel.classList.contains('active')) {
        closeSearchPanel();
    } else {
        openSearchPanel();
    }
}

function openSearchPanel() {
    const panel = document.getElementById('search-panel');
    const searchInput = document.getElementById('search-input');
    
    if (panel) {
        panel.classList.add('active');
        hideInfoPanel();
        hideStatusModal();
        
        if (searchInput) setTimeout(() => searchInput.focus(), 300);
        if (!searchInput || !searchInput.value.trim()) performSearch();
    }
}

function closeSearchPanel() {
    const panel = document.getElementById('search-panel');
    if (panel) {
        panel.classList.remove('active');
        clearHighlights();
    }
}

async function performSearch() {
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const categoryFilter = document.getElementById('category-filter');
    const resultsContainer = document.getElementById('search-results');
    const resultsCount = document.getElementById('results-count');
    
    if (!resultsContainer) return;
    
    const searchTerm = searchInput ? searchInput.value.trim() : '';
    const status = statusFilter ? statusFilter.value : 'all';
    const category = categoryFilter ? categoryFilter.value : 'all';
    
    try {
        resultsContainer.innerHTML = '<div class="search-loading">🔍 Suche läuft...</div>';
        
        const formData = new FormData();
        formData.append('action', 'search_objects');
        formData.append('search', searchTerm);
        formData.append('status', status);
        formData.append('category', category);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentSearchResults = result.results || [];
            displaySearchResults(currentSearchResults);
            
            if (resultsCount) {
                resultsCount.textContent = `${result.count} Ergebnis${result.count !== 1 ? 'se' : ''}`;
            }
            
            highlightMarkersOnMap(currentSearchResults);
        } else {
            resultsContainer.innerHTML = `<div class="search-error">❌ ${result.message || 'Fehler bei der Suche'}</div>`;
        }
    } catch (error) {
        console.error('Search error:', error);
        resultsContainer.innerHTML = '<div class="search-error">❌ Verbindungsfehler bei der Suche</div>';
    }
}

function displaySearchResults(results) {
    const resultsContainer = document.getElementById('search-results');
    if (!resultsContainer) return;
    
    if (results.length === 0) {
        resultsContainer.innerHTML = `
            <div class="search-empty">
                <div class="empty-icon">🔍</div>
                <p>Keine Objekte gefunden</p>
                <small>Versuchen Sie andere Suchbegriffe oder Filter</small>
            </div>
        `;
        return;
    }
    
    const canEdit = window.systemConfig.permissions.canEditMarker;
    
    resultsContainer.innerHTML = results.map(obj => {
        const statusClass = obj.status || 'available';
        const statusText = getStatusText(obj.status);
        const categoryInfo = window.systemConfig.categoriesMap[obj.category] || {
            display_name: 'Allgemein',
            icon: '📦',
            color: '#6bb032'
        };
        const categoryText = categoryInfo.icon + ' ' + categoryInfo.display_name;        

        // Wartungswarnung
        const maintenanceWarning = obj.maintenance_overdue ? 
            '<span style="color: #dc3545; font-size: 0.85rem;">⚠️ Wartung überfällig</span>' : '';
        
        return `
            <div class="search-result-item" data-id="${obj.id}">
                <div class="result-header">
                    <div class="result-title-row">
                        <h4 class="result-title">${escapeHtml(obj.title)}</h4>
                        <span class="result-id">#${obj.id}</span>
                    </div>
                    <div class="result-badges">
                        <span class="status-badge ${statusClass}">${statusText}</span>
                        <span class="category-badge" style="background: ${categoryInfo.color}; color: white;">${categoryText}</span>
                    </div>
                    ${maintenanceWarning}
                </div>
                
                ${obj.description ? `
                    <p class="result-description">${escapeHtml(obj.description).substring(0, 100)}${obj.description.length > 100 ? '...' : ''}</p>
                ` : ''}
                
                <div class="result-actions">
                    <button class="result-btn locate-btn" onclick="locateMarker(${obj.id})" title="Auf Karte anzeigen">
                        📍 Anzeigen
                    </button>
                    <button class="result-btn details-btn" onclick="showMarkerDetailsFromSearch(${obj.id})" title="Details anzeigen">
                        ℹ️ Details
                    </button>
                    ${canEdit ? `
                        <button class="result-btn edit-btn-small" onclick="handleEditFromSearch(${obj.id})" title="Bearbeiten">
                            ✏️
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function highlightMarkersOnMap(results) {
    clearHighlights();
    
    const allMarkers = document.querySelectorAll('.map-object');
    allMarkers.forEach(marker => marker.classList.add('dimmed'));
    
    results.forEach(obj => {
        const marker = document.querySelector(`.map-object[data-id="${obj.id}"]`);
        if (marker) {
            marker.classList.remove('dimmed');
            marker.classList.add('highlighted');
            highlightedMarkers.add(obj.id.toString());
        }
    });
}

function clearHighlights() {
    const allMarkers = document.querySelectorAll('.map-object');
    allMarkers.forEach(marker => {
        marker.classList.remove('dimmed', 'highlighted', 'pulsing');
    });
    highlightedMarkers.clear();
}

function locateMarker(objectId) {
    const marker = document.querySelector(`.map-object[data-id="${objectId}"]`);
    if (!marker) {
        showNotification('Marker nicht auf der Karte gefunden', 'warning');
        return;
    }
    
    document.querySelectorAll('.map-object.pulsing').forEach(m => m.classList.remove('pulsing'));
    marker.classList.add('pulsing');
    
    const container = document.getElementById('image-map-container');
    if (container) {
        marker.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
    }
    
    setTimeout(() => marker.classList.remove('pulsing'), 3000);
    showNotification('Marker wird angezeigt', 'success', 2000);
}

function showMarkerDetailsFromSearch(objectId) {
    const marker = document.querySelector(`.map-object[data-id="${objectId}"]`);
    if (marker) {
        marker.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        setTimeout(() => showInfoPanel(marker), 300);
    }
}

async function handleEditFromSearch(objectId) {
    const button = document.querySelector(`.edit-btn-small[onclick*="${objectId}"]`);
    if (button) {
        button.textContent = '⏳';
        button.disabled = true;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_object');
        formData.append('id', objectId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.object) {
            populateEditModal(result.object);
            showEditModal();
            closeSearchPanel();
        } else {
            showNotification(result.message || 'Fehler beim Laden der Objektdaten', 'error');
        }
    } catch (error) {
        console.error('Error loading object for edit:', error);
        showNotification('Verbindungsfehler beim Laden', 'error');
    } finally {
        if (button) {
            button.textContent = '✏️';
            button.disabled = false;
        }
    }
}

function resetSearch() {
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const categoryFilter = document.getElementById('category-filter');
    
    if (searchInput) searchInput.value = '';
    if (statusFilter) statusFilter.value = 'all';
    if (categoryFilter) categoryFilter.value = 'all';
    
    clearHighlights();
    performSearch();
    
    if (searchInput) searchInput.focus();
}

function getStatusText(status) {
    const statusTexts = {
        'available': 'Verfügbar',
        'rented': 'Vermietet',
        'maintenance': 'Wartung',
        'inactive': 'Inaktiv'
    };
    return statusTexts[status] || status;
}

// =============================================================================
// DRAG & DROP SYSTEM
// =============================================================================

function setupDragAndDrop() {
    if (!window.systemConfig.permissions.canEditMarker) return;
    
    const objects = document.querySelectorAll('.map-object');
    objects.forEach(obj => setupDragAndDropForElement(obj));
    
    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
}

function setupDragAndDropForElement(element) {
    if (!window.systemConfig.permissions.canEditMarker || !element) return;
    
    element.addEventListener('mousedown', handleMouseDown);
    element.addEventListener('contextmenu', e => e.preventDefault());
}

function handleMouseDown(e) {
    if (!e || !window.systemConfig.permissions.canEditMarker || isAddingObject) return;
    if (!isEditingPositions) return;
    if (e.button !== 0) return;
    
    e.preventDefault();
    hideInfoPanel();
    hideStatusModal();
    
    isDragging = true;
    hasMoved = false;
    currentDragElement = e.target.closest('.map-object');
    
    if (!currentDragElement) {
        isDragging = false;
        return;
    }
    
    const rect = currentDragElement.getBoundingClientRect();
    dragOffset.x = e.clientX - rect.left;
    dragOffset.y = e.clientY - rect.top;
    
    dragStartPosition = {
        xPercent: parseFloat(currentDragElement.dataset.xPercent) || 50,
        yPercent: parseFloat(currentDragElement.dataset.yPercent) || 50
    };
    
    currentDragElement.classList.add('dragging');
    currentDragElement.style.zIndex = '1000';
}

function handleMouseMove(e) {
    if (!isDragging || !currentDragElement) return;
    
    e.preventDefault();
    
    const backgroundImage = document.getElementById('background-image');
    if (!backgroundImage) return;
    
    const containerRect = backgroundImage.getBoundingClientRect();
    
    let pixelX = e.clientX - containerRect.left - dragOffset.x;
    let pixelY = e.clientY - containerRect.top - dragOffset.y;
    
    const markerSize = 24;
    pixelX = Math.max(0, Math.min(pixelX, containerRect.width - markerSize));
    pixelY = Math.max(0, Math.min(pixelY, containerRect.height - markerSize));
    
    const xPercent = (pixelX / containerRect.width) * 100;
    const yPercent = (pixelY / containerRect.height) * 100;
    
    currentDragElement.style.left = xPercent + '%';
    currentDragElement.style.top = yPercent + '%';
    
    if (!hasMoved && dragStartPosition) {
        const diffX = Math.abs(xPercent - dragStartPosition.xPercent);
        const diffY = Math.abs(yPercent - dragStartPosition.yPercent);
        
        if (diffX > 0.5 || diffY > 0.5) {
            hasMoved = true;
        }
    }
}

function handleMouseUp(e) {
    if (!isDragging || !currentDragElement) return;
    
    if (hasMoved) {
        const backgroundImage = document.getElementById('background-image');
        if (!backgroundImage) return;
        
        const containerRect = backgroundImage.getBoundingClientRect();
        const markerRect = currentDragElement.getBoundingClientRect();
        
        const centerX = markerRect.left + (markerRect.width / 2) - containerRect.left;
        const centerY = markerRect.top + (markerRect.height / 2) - containerRect.top;
        
        const xPercent = Math.max(0, Math.min(100, (centerX / containerRect.width) * 100));
        const yPercent = Math.max(0, Math.min(100, (centerY / containerRect.height) * 100));
        
        const objectId = currentDragElement.dataset.id;
        
        if (objectId) {
            updateObjectPosition(objectId, xPercent, yPercent);
            showNotification('Position gespeichert', 'success', 2000);
        }
    } else {
        if (dragStartPosition) {
            currentDragElement.style.left = dragStartPosition.xPercent + '%';
            currentDragElement.style.top = dragStartPosition.yPercent + '%';
        }
    }
    
    currentDragElement.classList.remove('dragging');
    currentDragElement.style.zIndex = '';
    
    isDragging = false;
    currentDragElement = null;
    dragStartPosition = null;
    hasMoved = false;
}

async function updateObjectPosition(objectId, xPercent, yPercent) {
    const formData = new FormData();
    formData.append('action', 'update_position');
    formData.append('id', objectId);
    formData.append('x_percent', xPercent.toFixed(3));
    formData.append('y_percent', yPercent.toFixed(3));
    
    try {
        await fetch('', { method: 'POST', body: formData });
    } catch (error) {
        console.error('Error updating position:', error);
    }
}

// =============================================================================
// INFO PANEL & STATUS MODAL
// =============================================================================

function showInfoPanel(markerElement) {
    if (!markerElement || !markerElement.dataset) return;
    
    const objectId = markerElement.dataset.id;
    if (!objectId) return;
    
    hideInfoPanel();
    hideStatusModal();
    
    const panel = document.createElement('div');
    panel.id = 'info-panel';
    panel.className = 'info-panel';
    
    const markerRect = markerElement.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    
    // Geschätzte Panel-Größe (wird später genauer)
    const estimatedPanelWidth = 350;
    const estimatedPanelHeight = 400;
    
    // Horizontale Position berechnen
    let left = markerRect.right + 10;
    
    // Wenn rechts nicht genug Platz, versuche links
    if (left + estimatedPanelWidth > viewportWidth - 10) {
        left = markerRect.left - estimatedPanelWidth - 10;
    }
    
    // Wenn auch links nicht passt, zentriere es
    if (left < 10) {
        left = Math.max(10, (viewportWidth - estimatedPanelWidth) / 2);
    }
    
    // Vertikale Position berechnen
    let top = markerRect.top;
    
    // Wenn unten nicht genug Platz, nach oben verschieben
    if (top + estimatedPanelHeight > viewportHeight - 10) {
        top = viewportHeight - estimatedPanelHeight - 10;
    }
    
    // Mindestabstand oben
    top = Math.max(10, top);
    
    // Maximale Höhe berechnen
    const maxHeight = viewportHeight - top - 20;
    
    panel.style.cssText = `
        position: fixed;
        left: ${left}px;
        top: ${top}px;
        background: white;
        border: 2px solid #ddd;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        max-width: 350px;
        min-width: 300px;
        z-index: 2000;
        animation: slideInRight 0.3s ease;
        max-height: ${maxHeight}px;
        overflow-y: auto;
        font-family: Arial, sans-serif;
    `;
    
    loadObjectDataForPanel(objectId, panel, markerElement);
    
    document.body.appendChild(panel);
    currentInfoPanel = panel;
    
    // Nach dem Rendern: Exakte Position anpassen wenn nötig
    setTimeout(() => {
        if (!panel.parentNode) return;
        
        const actualRect = panel.getBoundingClientRect();
        
        // Wenn immer noch abgeschnitten, korrigiere
        if (actualRect.right > viewportWidth - 10) {
            const newLeft = viewportWidth - actualRect.width - 10;
            panel.style.left = Math.max(10, newLeft) + 'px';
        }
        
        if (actualRect.bottom > viewportHeight - 10) {
            const newTop = viewportHeight - actualRect.height - 10;
            panel.style.top = Math.max(10, newTop) + 'px';
        }
    }, 50);
}

function hideInfoPanel() {
    if (currentInfoPanel) {
        currentInfoPanel.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => {
            if (currentInfoPanel && currentInfoPanel.parentNode) {
                currentInfoPanel.parentNode.removeChild(currentInfoPanel);
            }
            currentInfoPanel = null;
            currentObjectData = null;
        }, 300);
    }
}

async function loadObjectDataForPanel(objectId, panel, markerElement) {
    if (!objectId || !panel || !markerElement) return;
    
    panel.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #2c5530;">Laden...</h3>
            <button class="close-info-panel" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">×</button>
        </div>
        <p style="color: #666;">Objektdaten werden geladen...</p>
    `;
    
    // Wenn nicht eingeloggt, zeige nur Basis-Infos aus dem DOM
    if (!window.systemConfig.isLoggedIn) {
        renderInfoPanelFromDOM(panel, markerElement);
        return;
    }
    
    // Rest des Codes bleibt gleich...
    try {
        const formData = new FormData();
        formData.append('action', 'get_object');
        formData.append('id', objectId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentObjectData = result.object;
            renderInfoPanel(panel, result.object);
        } else {
            renderInfoPanelFromDOM(panel, markerElement);
        }
    } catch (error) {
        console.error('Error loading object data:', error);
        renderInfoPanelFromDOM(panel, markerElement);
    }
}

function renderInfoPanelFromDOM(panel, markerElement) {
    if (!panel || !markerElement) return;
    
    const title = (markerElement.dataset && markerElement.dataset.title) ? markerElement.dataset.title : 'Unbekanntes Objekt';
    const category = (markerElement.dataset && markerElement.dataset.category) ? markerElement.dataset.category : 'Allgemein';
    
    panel.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
            <h3 style="margin: 0; color: #2c5530; font-size: 1.3rem;">${escapeHtml(title)}</h3>
            <button class="close-info-panel" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 5px; border-radius: 50%; transition: all 0.2s;">×</button>
        </div>
        
        <div style="margin-bottom: 15px;">
            <span style="background: #f8f9fa; color: #6c757d; padding: 4px 12px; border-radius: 15px; font-size: 0.9rem; font-weight: 500;">
                ${escapeHtml(category)}
            </span>
        </div>
        
        <p style="color: #666; font-style: italic; padding: 1rem; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #6bb032;">
            ℹ️ Melden Sie sich an, um weitere Details zu sehen
        </p>
    `;
}

// =============================================================================
// STATUS MODAL SYSTEM
// =============================================================================

function showStatusModal(button) {
    if (!button || !button.dataset || !currentObjectData) return;
    
    const objectId = button.dataset.id;
    const currentStatus = button.dataset.currentStatus;
    
    hideStatusModal();
    
    const modal = document.createElement('div');
    modal.id = 'status-modal';
    modal.className = 'status-modal';
    
    const buttonRect = button.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    
    const modalWidth = 280;
    const modalHeight = 200;
    
    let left = buttonRect.left;
    let top = buttonRect.bottom + 5;
    
    if (left + modalWidth > viewportWidth) {
        left = viewportWidth - modalWidth - 10;
    }
    
    if (left < 10) {
        left = 10;
    }
    
    if (top + modalHeight > viewportHeight) {
        top = buttonRect.top - modalHeight - 5;
        
        if (top < 10) {
            top = Math.max(10, (viewportHeight - modalHeight) / 2);
        }
    }
    
    modal.style.cssText = `
        position: fixed;
        left: ${left}px;
        top: ${top}px;
        background: white;
        border: 2px solid #4a90e2;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        z-index: 3000;
        animation: popIn 0.3s ease;
        font-family: Arial, sans-serif;
        min-width: 280px;
        max-width: 90vw;
    `;
    
    const statusOptions = [
        { value: 'available', text: 'Verfügbar', color: '#28a745' },
        { value: 'rented', text: 'Vermietet', color: '#dc3545' },
        { value: 'maintenance', text: 'Wartung', color: '#ffc107' }
    ];
    
    modal.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0; color: #4a90e2; font-size: 1.1rem;">Status auswählen</h4>
            <button class="close-status-modal-btn" style="background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #666; padding: 2px 6px; border-radius: 50%;">×</button>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
            ${statusOptions.map(option => `
                <button class="status-option-btn" 
                        data-id="${objectId}" 
                        data-status="${option.value}"
                        style="display: flex; align-items: center; gap: 10px; padding: 12px; 
                               border: 2px solid ${option.value === currentStatus ? option.color : '#ddd'}; 
                               background: ${option.value === currentStatus ? option.color + '20' : 'white'}; 
                               border-radius: 8px; cursor: pointer; font-size: 0.9rem; transition: all 0.2s ease; text-align: left;">
                    <div style="width: 14px; height: 14px; border-radius: 50%; background-color: ${option.color}; flex-shrink: 0;"></div>
                    <span style="font-weight: 500;">${option.text}</span>
                </button>
            `).join('')}
        </div>
        
        <div style="text-align: center;">
            <button class="cancel-status-modal-btn" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem;">
                Abbrechen
            </button>
        </div>
    `;
    
    document.body.appendChild(modal);
    statusModal = modal;
    
    setupStatusModalEvents(modal);
    
    modal.querySelectorAll('.status-option-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            if (!this.style.backgroundColor.includes('20')) {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
            }
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = '';
            this.style.boxShadow = '';
        });
    });
}

function setupStatusModalEvents(modal) {
    if (!modal) return;
    
    const closeBtn = modal.querySelector('.close-status-modal-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            hideStatusModal();
        });
    }
    
    const cancelBtn = modal.querySelector('.cancel-status-modal-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            hideStatusModal();
        });
    }
    
    modal.querySelectorAll('.status-option-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            selectNewStatus(this);
        });
    });
    
    setTimeout(() => {
        document.addEventListener('click', handleStatusModalOutsideClick, true);
    }, 100);
    
    document.addEventListener('keydown', handleStatusModalEscKey);
}

function handleStatusModalOutsideClick(e) {
    if (!statusModal) return;
    
    if (!statusModal.contains(e.target) && !e.target.classList.contains('edit-status-btn')) {
        hideStatusModal();
    }
}

function handleStatusModalEscKey(e) {
    if (e.key === 'Escape' && statusModal) {
        hideStatusModal();
    }
}

function hideStatusModal() {
    if (statusModal) {
        document.removeEventListener('click', handleStatusModalOutsideClick, true);
        document.removeEventListener('keydown', handleStatusModalEscKey);
        
        statusModal.style.animation = 'popOut 0.3s ease';
        setTimeout(() => {
            if (statusModal && statusModal.parentNode) {
                statusModal.parentNode.removeChild(statusModal);
            }
            statusModal = null;
        }, 300);
    }
}

async function selectNewStatus(button) {
    if (!button || !button.dataset) return;
    
    const objectId = button.dataset.id;
    const newStatus = button.dataset.status;
    
    button.style.opacity = '0.6';
    button.style.transform = 'scale(0.95)';
    
    try {
        const formData = new FormData();
        formData.append('action', 'set_status');
        formData.append('id', objectId);
        formData.append('status', newStatus);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const markerElement = document.querySelector(`.map-object[data-id="${objectId}"]`);
            if (markerElement) {
                markerElement.className = markerElement.className.replace(/\b(available|rented|maintenance)\b/g, '');
                markerElement.classList.add(newStatus);
            }
            
            if (currentObjectData && currentObjectData.id == objectId) {
                currentObjectData.status = newStatus;
            }
            
            hideStatusModal();
            
            if (currentInfoPanel && currentObjectData) {
                renderInfoPanel(currentInfoPanel, currentObjectData);
            }
            
            const searchPanel = document.getElementById('search-panel');
            if (searchPanel && searchPanel.classList.contains('active')) {
                performSearch();
            }
            
            showNotification(`Status geändert: ${getStatusText(newStatus)}`, 'success', 2000);
        } else {
            showNotification(result.message || 'Fehler beim Ändern des Status', 'error');
        }
    } catch (error) {
        console.error('Status change error:', error);
        showNotification('Verbindungsfehler beim Ändern', 'error');
    } finally {
        button.style.opacity = '';
        button.style.transform = '';
    }
}

// =============================================================================
// USER MANAGEMENT
// =============================================================================

async function loadAvailableRoles() {
    try {
        const formData = new FormData();
        formData.append('action', 'get_roles_list');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            availableRoles = result.roles;
            
            const newRoleSelect = document.getElementById('new-role');
            if (newRoleSelect) {
                newRoleSelect.innerHTML = '<option value="">-- Rolle wählen --</option>' +
                    result.roles.map(role => `<option value="${role.id}">${escapeHtml(role.display_name)}</option>`).join('');
            }
        }
    } catch (error) {
        console.error('Error loading roles:', error);
    }
}

async function loadUsers() {
    const usersList = document.getElementById('users-list');
    if (!usersList) return;
    
    try {
        usersList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #666;">Lade Benutzer...</div>';
        
        const formData = new FormData();
        formData.append('action', 'get_users');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.users) {
            if (result.users.length === 0) {
                usersList.innerHTML = '<div class="empty-state">Keine Benutzer gefunden</div>';
                return;
            }
            
            const canEdit = window.systemConfig.permissions.canEditUsers;
            const canDelete = window.systemConfig.permissions.canDeleteUsers;
            
            // Tabellen-Layout
            usersList.innerHTML = `
                <div class="users-table-wrapper">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Benutzername</th>
                                <th>E-Mail</th>
                                <th>Rolle</th>
                                <th>Erstellt</th>
                                <th>Letzter Login</th>
                                ${canEdit || canDelete ? '<th>Aktionen</th>' : ''}
                            </tr>
                        </thead>
                        <tbody>
                            ${result.users.map(user => {
                                const notificationBadge = user.receive_maintenance_notifications 
                                    ? '<span class="mini-badge maintenance-badge" title="Erhält Wartungsbenachrichtigungen">🔧</span>'
                                    : '';
                                
                                const emailDisplay = user.email 
                                    ? escapeHtml(user.email)
                                    : '<span style="color: #999; font-style: italic;">Keine E-Mail</span>';
                                
                                const createdDate = formatDate(user.created_at);
                                const loginDate = user.last_login ? formatDate(user.last_login) : '<span style="color: #999; font-style: italic;">Noch nie</span>';
                                
                                return `
                                    <tr class="user-table-row">
                                        <td>
                                            <div class="user-name-cell">
                                                <strong>${escapeHtml(user.username)}</strong>
                                                ${notificationBadge}
                                            </div>
                                        </td>
                                        <td class="email-cell">${emailDisplay}</td>
                                        <td>
                                            <span class="role-badge">${escapeHtml(user.role_display_name || user.role_name)}</span>
                                        </td>
                                        <td class="date-cell">${createdDate}</td>
                                        <td class="date-cell">${loginDate}</td>
                                        ${canEdit || canDelete ? `
                                            <td class="actions-cell">
                                                ${canEdit ? `
                                                    <button class="table-btn edit-btn" onclick="editUser(${user.id}, '${escapeHtml(user.username)}', ${user.role_id}, '${escapeHtml(user.email || '')}', ${user.receive_maintenance_notifications})" title="Bearbeiten">
                                                        ✏️
                                                    </button>
                                                ` : ''}
                                                
                                                ${canDelete && user.id != window.systemConfig.userId ? `
                                                    <button class="table-btn delete-btn" onclick="deleteUser(${user.id}, '${escapeHtml(user.username)}')" title="Löschen">
                                                        🗑️
                                                    </button>
                                                ` : canDelete ? `
                                                    <button class="table-btn delete-btn" disabled title="Sie können sich nicht selbst löschen">
                                                        🗑️
                                                    </button>
                                                ` : ''}
                                            </td>
                                        ` : ''}
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            usersList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #dc3545;">Fehler beim Laden der Benutzer</div>';
        }
    } catch (error) {
        console.error('Load users error:', error);
        usersList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #dc3545;">Verbindungsfehler</div>';
    }
}

async function handleAddUser(e) {
    if (!e) return;
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'add_user');
    
    const username = formData.get('username');
    const password = formData.get('password');
    const roleId = formData.get('role_id');
    
    if (!username || username.trim().length < 3) {
        showNotification('Benutzername muss mindestens 3 Zeichen lang sein', 'warning');
        return;
    }
    
    if (!password || password.length < 6) {
        showNotification('Passwort muss mindestens 6 Zeichen lang sein', 'warning');
        return;
    }
    
    if (!roleId) {
        showNotification('Bitte wählen Sie eine Rolle aus', 'warning');
        return;
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : '';
    
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Erstellen...';
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Benutzer erfolgreich erstellt', 'success');
            form.reset();
            loadUsers();
        } else {
            showNotification(result.message || 'Fehler beim Erstellen', 'error');
        }
    } catch (error) {
        console.error('Add user error:', error);
        showNotification('Verbindungsfehler beim Erstellen', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }
}

function editUser(userId, username, roleId, email, receiveNotifications) {
    hideEditUserModal();
    
    const modal = document.createElement('div');
    modal.id = 'edit-user-modal';
    modal.className = 'modal';
    modal.style.cssText = `
        display: flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(8px);
        opacity: 1;
        animation: fadeIn 0.3s ease;
    `;
    
    const roleOptions = availableRoles.map(role => 
        `<option value="${role.id}" ${role.id == roleId ? 'selected' : ''}>${escapeHtml(role.display_name)}</option>`
    ).join('');
    
    modal.innerHTML = `
        <div class="modal-content" style="background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.25); width: 90%; max-width: 600px; animation: slideIn 0.3s ease;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
                <h2 style="margin: 0; color: #2c5530; font-size: 1.5rem;">Benutzer bearbeiten</h2>
                <button class="close-edit-user-modal" style="background: none; border: none; font-size: 1.75rem; cursor: pointer; color: #666; width: 40px; height: 40px; border-radius: 50%;">×</button>
            </div>
            
            <form id="edit-user-form" style="margin: 0;">
                <input type="hidden" id="edit-user-id" value="${userId}">
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="edit-user-username" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #343a40;">Benutzername: *</label>
                    <input type="text" id="edit-user-username" value="${escapeHtml(username)}" required minlength="3" maxlength="50"
                           style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem;">
                </div>
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="edit-user-role" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #343a40;">Rolle: *</label>
                    <select id="edit-user-role" required style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem;">
                        ${roleOptions}
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="edit-user-email" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #343a40;">E-Mail (optional):</label>
                    <input type="email" id="edit-user-email" value="${escapeHtml(email)}" maxlength="255"
                           style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem;">
                    <small style="color: #666; font-size: 0.85rem;">Wird für Wartungsbenachrichtigungen verwendet</small>
                </div>
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none;">
                        <input type="checkbox" id="edit-user-receive-notifications" ${receiveNotifications ? 'checked' : ''}
                               style="width: 18px; height: 18px; cursor: pointer;">
                        <span>Wartungsbenachrichtigungen per E-Mail erhalten</span>
                    </label>
                    <small style="color: #666; font-size: 0.85rem; margin-left: 26px; display: block; margin-top: 4px;">
                        Benutzer erhält automatisch E-Mails wenn Wartungen fällig sind
                    </small>
                </div>
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="edit-user-password" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #343a40;">Neues Passwort (optional):</label>
                    <input type="password" id="edit-user-password" minlength="6" placeholder="Leer lassen um Passwort nicht zu ändern"
                           style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem;">
                    <small style="color: #666; font-size: 0.85rem;">Mindestens 6 Zeichen, wenn geändert werden soll</small>
                </div>
                
                <div class="form-actions" style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e0e0e0;">
                    <button type="button" class="close-edit-user-modal" style="background: #6c757d; color: white; border: none; padding: 0.75rem 1.25rem; border-radius: 8px; cursor: pointer;">
                        Abbrechen
                    </button>
                    <button type="submit" style="background: #4a90e2; color: white; border: none; padding: 0.75rem 1.25rem; border-radius: 8px; cursor: pointer;">
                        Änderungen speichern
                    </button>
                </div>
            </form>
        </div>
    `;
    
    document.body.appendChild(modal);
    editUserModal = modal;
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal || e.target.classList.contains('close-edit-user-modal')) {
            hideEditUserModal();
        }
    });
    
    const form = modal.querySelector('#edit-user-form');
    form.addEventListener('submit', handleEditUser);
    
    setTimeout(() => {
        const usernameInput = modal.querySelector('#edit-user-username');
        if (usernameInput) usernameInput.focus();
    }, 100);
}

function hideEditUserModal() {
    if (editUserModal) {
        editUserModal.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            if (editUserModal && editUserModal.parentNode) {
                editUserModal.parentNode.removeChild(editUserModal);
            }
            editUserModal = null;
        }, 300);
    }
}

async function handleEditUser(e) {
    if (!e) return;
    e.preventDefault();
    
    const form = e.target;
    const userId = document.getElementById('edit-user-id').value;
    const username = document.getElementById('edit-user-username').value.trim();
    const roleId = document.getElementById('edit-user-role').value;
    const email = document.getElementById('edit-user-email').value.trim();
    const receiveNotifications = document.getElementById('edit-user-receive-notifications').checked;
    const password = document.getElementById('edit-user-password').value;
    
    if (!username || username.length < 3) {
        showNotification('Benutzername muss mindestens 3 Zeichen lang sein', 'warning');
        return;
    }
    
    if (password && password.length < 6) {
        showNotification('Neues Passwort muss mindestens 6 Zeichen lang sein', 'warning');
        return;
    }
    
    if (!roleId) {
        showNotification('Bitte wählen Sie eine Rolle aus', 'warning');
        return;
    }
    
    if (receiveNotifications && !email) {
        showNotification('E-Mail-Adresse erforderlich für Benachrichtigungen', 'warning');
        return;
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : '';
    
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Speichern...';
        }
        
        const formData = new FormData();
        formData.append('action', 'update_user');
        formData.append('id', userId);
        formData.append('username', username);
        formData.append('role_id', roleId);
        formData.append('email', email);
        if (receiveNotifications) {
            formData.append('receive_notifications', '1');
        }
        if (password) {
            formData.append('password', password);
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            hideEditUserModal();
            showNotification('Benutzer erfolgreich aktualisiert', 'success');
            loadUsers();
        } else {
            showNotification(result.message || 'Fehler beim Aktualisieren', 'error');
        }
    } catch (error) {
        console.error('Edit user error:', error);
        showNotification('Verbindungsfehler beim Speichern', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }
}
async function deleteUser(userId, username) {
    if (!userId || !username) return;
    
    if (!confirm(`Möchten Sie den Benutzer "${username}" wirklich löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden.`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_user');
        formData.append('id', userId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Benutzer erfolgreich gelöscht', 'success');
            loadUsers();
        } else {
            showNotification(result.message || 'Fehler beim Löschen', 'error');
        }
    } catch (error) {
        console.error('Delete user error:', error);
        showNotification('Verbindungsfehler beim Löschen', 'error');
    }
}

// =============================================================================
// ADD OBJECT MODE & MODALS
// =============================================================================

function enableAddObjectMode() {
    if (!window.systemConfig.permissions.canCreateMarker || !window.systemConfig.hasBackground) {
        if (!window.systemConfig.hasBackground) {
            showNotification('Bitte laden Sie zuerst ein Hintergrundbild in den Einstellungen hoch', 'warning');
            if (window.systemConfig.permissions.canEditSettings) {
                window.location.href = 'settings.php';
            }
        }
        return;
    }
    
    isAddingObject = true;
    hideInfoPanel();
    hideStatusModal();
    closeSearchPanel();
    
    const backgroundImage = document.getElementById('background-image');
    if (backgroundImage) {
        backgroundImage.style.cursor = 'crosshair';
        backgroundImage.classList.add('adding-mode');
    }
    
    showNotification('Klicken Sie auf das Bild, um einen neuen Marker zu platzieren (ESC zum Abbrechen)', 'info', 5000);
}

function disableAddObjectMode() {
    isAddingObject = false;
    
    const backgroundImage = document.getElementById('background-image');
    if (backgroundImage) {
        backgroundImage.style.cursor = '';
        backgroundImage.classList.remove('adding-mode');
    }
}

function handleBackgroundClick(e) {
    if (!e || !window.systemConfig.permissions.canCreateMarker || !isAddingObject) return;
    if (e.target.closest('.map-object')) return;
    
    const rect = e.currentTarget.getBoundingClientRect();
    const pixelX = e.clientX - rect.left;
    const pixelY = e.clientY - rect.top;
    
    if (pixelX < 0 || pixelY < 0 || pixelX > rect.width || pixelY > rect.height) return;
    
    const xPercent = (pixelX / rect.width) * 100;
    const yPercent = (pixelY / rect.height) * 100;
    
    disableAddObjectMode();
    showAddObjectModal(xPercent, yPercent);
}

function showLoginModal() {
    const modal = document.getElementById('login-modal');
    if (modal) modal.classList.add('show');
}

function showAddObjectModal(xPercent, yPercent) {
    const modal = document.getElementById('add-object-modal');
    const form = document.getElementById('add-object-form');
    
    if (modal && form) {
        form.dataset.xPercent = xPercent.toFixed(3);
        form.dataset.yPercent = yPercent.toFixed(3);
        modal.classList.add('show');
        showNotification(`Position: ${xPercent.toFixed(1)}% / ${yPercent.toFixed(1)}%`, 'info', 3000);
    }
}

function showEditModal() {
    const modal = document.getElementById('edit-object-modal');
    if (modal) {
        hideInfoPanel();
        hideStatusModal();
        modal.classList.add('show');
        
        const titleInput = document.getElementById('edit-obj-title');
        if (titleInput) {
            setTimeout(() => titleInput.focus(), 100);
        }
    }
}

function showUsersModal() {
    const modal = document.getElementById('users-modal');
    if (modal) {
        modal.classList.add('show');
        loadUsers();
    }
}

function hideAllModals() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => modal.classList.remove('show'));
    hideEditUserModal();
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function createObjectElement(data) {
    const div = document.createElement('div');
    
    // NEU: Lagergerät-Klasse hinzufügen
    let className = 'map-object ';
    if (data.is_storage_device == 1) {
        className += 'storage-device';
    } else {
        className += (data.status || 'available');
    }
    
    div.className = className;
    
    if (data.id) div.dataset.id = data.id;
    if (data.title) div.dataset.title = data.title;
    if (data.category) div.dataset.category = data.category;
    if (data.is_storage_device) div.dataset.isStorageDevice = data.is_storage_device;
    
    const xPercent = data.xPercent || 50;
    const yPercent = data.yPercent || 50;
    
    div.dataset.xPercent = xPercent;
    div.dataset.yPercent = yPercent;
    
    updateMarkerPosition(div, xPercent, yPercent);
    
    if (window.systemConfig.permissions.canEditMarker) {
        if (isEditingPositions) {
            div.style.cursor = 'move';
            div.classList.add('editable');
        } else {
            div.style.cursor = 'pointer';
        }
        
        const dragIndicator = document.createElement('div');
        dragIndicator.className = 'drag-indicator';
        div.appendChild(dragIndicator);
    }
    
    return div;
}

function updateLegend() {
    const legend = document.getElementById('legend');
    if (legend) {
        legend.innerHTML = `
            <h4>Legende</h4>
            <div class="legend-items">
                <div class="legend-item">
                    <div class="legend-marker available"></div>
                    <span>Verfügbar</span>
                </div>
                <div class="legend-item">
                    <div class="legend-marker rented"></div>
                    <span>Vermietet</span>
                </div>
                <div class="legend-item">
                    <div class="legend-marker maintenance"></div>
                    <span>Wartung</span>
                </div>
                <div class="legend-item">
                    <div class="legend-marker storage-device"></div>
                    <span>Lagergerät</span>
                </div>
            </div>
        `;
    }
}

function showLoadingOverlay(text = 'Laden...') {
    const overlay = document.getElementById('loading-overlay');
    const loadingText = document.getElementById('loading-text');
    
    if (overlay) {
        if (loadingText) loadingText.textContent = text;
        overlay.style.display = 'flex';
    }
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

async function loadAndApplySettings() {
    try {
        const formData = new FormData();
        formData.append('action', 'get_settings');
        
        const response = await fetch('settings.php', {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            const result = await response.json();
            if (result.success) {
                applySettings(result.settings);
                return;
            }
        }
    } catch (error) {
        console.log('Settings not available or error loading:', error);
    }
    
    const customCSS = localStorage.getItem('mapCustomCSS');
    if (customCSS) {
        applyCustomCSS(customCSS);
    }
}

function applySettings(settings) {
    const markerSize = settings.marker_size || 24;
    const borderWidth = settings.marker_border_width || 3;
    const hoverScale = settings.marker_hover_scale || 1.3;
    const enablePulse = settings.enable_marker_pulse !== false;
    const showLegend = settings.show_legend !== false;
    const tooltipDelay = settings.tooltip_delay || 0;
    const backgroundBlur = settings.background_blur_admin || false;
    
    const css = `
        .map-object {
            width: ${markerSize}px !important;
            height: ${markerSize}px !important;
            border-width: ${borderWidth}px !important;
            ${enablePulse ? '' : 'animation: none !important;'}
        }
        
        .map-object::before {
            ${enablePulse ? '' : 'display: none !important;'}
        }
        
        .map-object:hover {
            transform: translate(-50%, -50%) scale(${hoverScale}) !important;
        }
        
        .legend {
            display: ${showLegend ? 'block' : 'none'} !important;
        }
        
        .background-image.adding-mode {
            ${backgroundBlur ? 'filter: blur(2px);' : ''}
        }
    `;
    
    applyCustomCSS(css);
    
    window.tooltipDelay = tooltipDelay;
    currentSettings = settings;
}

function applyCustomCSS(css) {
    const existingStyle = document.getElementById('custom-map-styles');
    if (existingStyle) {
        existingStyle.remove();
    }
    
    const style = document.createElement('style');
    style.id = 'custom-map-styles';
    style.textContent = css;
    document.head.appendChild(style);
}

function formatDate(dateString) {
    if (!dateString) return '-';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('de-DE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }) + ' ' + date.toLocaleTimeString('de-DE', {
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch {
        return dateString;
    }
}

function filterMarkersByCategory(category) {
    const markers = document.querySelectorAll('.map-object');
    markers.forEach(marker => {
        if (category === 'all' || marker.dataset.category === category) {
            marker.style.display = '';
        } else {
            marker.style.display = 'none';
        }
    });
}

// =============================================================================
// DASHBOARD FUNKTIONALITÄT
// =============================================================================

function initializeDashboard() {
    if (!window.systemConfig || !window.systemConfig.isLoggedIn) {
        console.log('Dashboard: User not logged in');
        return;
    }
    
    const dashboard = document.getElementById('stats-dashboard');
    const dashboardToggle = document.getElementById('dashboard-toggle');
    const refreshBtn = document.getElementById('refresh-dashboard');
    
    if (!dashboard) {
        console.warn('Dashboard: stats-dashboard element not found');
        return;
    }
    
    if (!dashboardToggle) {
        console.warn('Dashboard: dashboard-toggle button not found');
        return;
    }
    
    console.log('Dashboard: Initializing...');
    
    // Toggle Event
    dashboardToggle.addEventListener('click', function(e) {
        e.preventDefault();
        console.log('Dashboard: Toggle clicked');
        dashboard.classList.toggle('collapsed');
        const isCollapsed = dashboard.classList.contains('collapsed');
        localStorage.setItem('dashboardCollapsed', isCollapsed);
        console.log('Dashboard: Collapsed =', isCollapsed);
        
        if (!isCollapsed) {
            updateDashboard();
        }
    });
    
    // Refresh Event
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            console.log('Dashboard: Refresh clicked');
            updateDashboard();
            showNotification('Dashboard aktualisiert', 'success', 2000);
        });
    }
    
    // Restore State
    const savedState = localStorage.getItem('dashboardCollapsed');
    console.log('Dashboard: Saved state =', savedState);
    if (savedState === 'false') {
        dashboard.classList.remove('collapsed');
        updateDashboard();
    }
    
    console.log('Dashboard: Initialized successfully');
}

// Dashboard Scroll-Position speichern
document.addEventListener('DOMContentLoaded', function() {
    const dashboardContent = document.querySelector('.dashboard-content');
    if (dashboardContent) {
        // Scroll-Position wiederherstellen
        const savedScroll = sessionStorage.getItem('dashboardScroll');
        if (savedScroll) {
            dashboardContent.scrollTop = parseInt(savedScroll);
        }
        
        // Scroll-Position speichern
        dashboardContent.addEventListener('scroll', debounce(function() {
            sessionStorage.setItem('dashboardScroll', this.scrollTop);
        }, 100));
    }
});

async function updateDashboard() {
    console.log('Dashboard: Updating...');
    const markers = document.querySelectorAll('.map-object');
    
    let total = markers.length;
    let available = 0;
    let rented = 0;
    let maintenance = 0;
    
    markers.forEach(marker => {
        if (marker.classList.contains('available')) available++;
        else if (marker.classList.contains('rented')) rented++;
        else if (marker.classList.contains('maintenance')) maintenance++;
    });
    
    console.log('Dashboard: Stats -', {total, available, rented, maintenance});
    
    updateElement('kpi-total', total);
    updateElement('kpi-available', available);
    updateElement('kpi-rented', rented);
    updateElement('kpi-maintenance', maintenance);
    
    const utilizationPercent = total > 0 ? Math.round((rented / total) * 100) : 0;
    updateUtilization(utilizationPercent, available, rented);
    updateTrendBars(total, available, rented, maintenance);
    await loadMaintenanceThisWeek();
}

function updateElement(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function updateUtilization(percent, available, rented) {
    const percentElement = document.getElementById('utilization-percentage');
    const circle = document.getElementById('utilization-circle');
    const availableElement = document.getElementById('legend-available');
    const rentedElement = document.getElementById('legend-rented');
    
    if (percentElement) percentElement.textContent = percent + '%';
    if (availableElement) availableElement.textContent = `${available} Verfügbar`;
    if (rentedElement) rentedElement.textContent = `${rented} Vermietet`;
    
    if (circle) {
        const circumference = 251.2;
        const offset = circumference - (percent / 100) * circumference;
        circle.style.strokeDashoffset = offset;
        
        if (percent >= 80) {
            circle.style.stroke = '#dc3545';
        } else if (percent >= 50) {
            circle.style.stroke = '#ffc107';
        } else {
            circle.style.stroke = 'var(--secondary-color)';
        }
    }
}

function updateTrendBars(total, available, rented, maintenance) {
    updateTrendBar('available', available, total);
    updateTrendBar('rented', rented, total);
    updateTrendBar('maintenance', maintenance, total);
}

function updateTrendBar(type, value, total) {
    const fillElement = document.getElementById(`trend-${type}`);
    const valueElement = document.getElementById(`trend-${type}-val`);
    
    if (fillElement && valueElement) {
        const percent = total > 0 ? (value / total) * 100 : 0;
        fillElement.style.width = percent + '%';
        valueElement.textContent = value;
    }
}

async function loadMaintenanceThisWeek() {
    const container = document.getElementById('maintenance-this-week');
    if (!container) return;
    
    const markers = document.querySelectorAll('.map-object');
    const now = new Date();
    const oneWeekFromNow = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
    
    const maintenanceItems = [];
    
    markers.forEach(marker => {
        const maintenanceDue = marker.dataset.maintenanceDue;
        if (!maintenanceDue) return;
        
        const dueDate = new Date(maintenanceDue);
        if (dueDate <= oneWeekFromNow) {
            const title = marker.dataset.title || 'Unbekannt';
            const isOverdue = dueDate < now;
            
            maintenanceItems.push({
                title: title,
                date: dueDate,
                dateStr: formatDate(maintenanceDue),
                isOverdue: isOverdue
            });
        }
    });
    
    maintenanceItems.sort((a, b) => a.date - b.date);
    
    if (maintenanceItems.length === 0) {
        container.innerHTML = '<div class="maintenance-empty">Keine Wartungen diese Woche fällig</div>';
    } else {
        container.innerHTML = maintenanceItems.map(item => `
            <div class="maintenance-item ${item.isOverdue ? 'overdue' : ''}">
                <div class="maintenance-item-title">${escapeHtml(item.title)}</div>
                <div class="maintenance-item-date">
                    ${item.isOverdue ? 'Überfällig: ' : 'Fällig: '} ${item.dateStr}
                </div>
            </div>
        `).join('');
    }
}

// Warte bis DOM vollständig geladen ist, dann initialisiere Dashboard
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing dashboard...');
    setTimeout(initializeDashboard, 100);
});

// NOTFALL Dashboard-Toggle
window.addEventListener('load', function() {
    setTimeout(function() {
        const dashboard = document.getElementById('stats-dashboard');
        const toggle = document.getElementById('dashboard-toggle');
        
        if (dashboard && toggle) {
            console.log('Dashboard-Elemente gefunden');
            
            // Entferne alle alten Listener
            const newToggle = toggle.cloneNode(true);
            toggle.parentNode.replaceChild(newToggle, toggle);
            
            // Setze neuen Listener
            newToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Toggle geklickt - collapsed:', dashboard.classList.contains('collapsed'));
                dashboard.classList.toggle('collapsed');
                localStorage.setItem('dashboardCollapsed', dashboard.classList.contains('collapsed'));
            });
            
            console.log('Dashboard-Toggle bereit');
        } else {
            console.error('Dashboard-Elemente NICHT gefunden', {dashboard, toggle});
        }
    }, 500);
});

console.log('Script.js with Dashboard loaded');

console.log('Complete Maintenance Management System loaded successfully');