<?php
// user_details_modal.php
?>
<!-- User Details Modal -->
<div class="modal" id="userDetailsModal" style="display: none;">
    <div class="modal-content" style="max-width: 95%; width: 95%; height: 95vh; margin: 2.5vh auto;">
        <div class="modal-header">
            <h3><i class="fas fa-user"></i> User Details - <span id="modalUserName">Loading...</span></h3>
            <button class="close-modal" onclick="closeModal('userDetailsModal')">&times;</button>
        </div>
        
        <div class="modal-body" style="height: calc(95vh - 120px); overflow-y: auto;">
            <!-- Tabs Navigation -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('basicInfo')">Basic Info</button>
                <button class="tab" onclick="switchTab('financialInfo')">Financial Info</button>
                <button class="tab" onclick="switchTab('activityInfo')">Activity</button>
                <button class="tab" onclick="switchTab('bettingHistory')">Betting History</button>
                <button class="tab" onclick="switchTab('transactions')">Transactions</button>
            </div>

            <!-- Basic Information Tab -->
            <div id="basicInfo" class="tab-content active">
                <form id="basicInfoForm" class="edit-form">
                    <input type="hidden" name="user_id" id="modalUserIdInput">
                    <div class="user-info-grid">
                        <div class="info-card">
                            <div class="info-label">User ID</div>
                            <div class="info-value" id="modalUserId">-</div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Username</div>
                            <input type="text" name="username" id="modalUsername" class="editable-field form-control">
                        </div>
                        <div class="info-card">
                            <div class="info-label">Email</div>
                            <input type="email" name="email" id="modalEmail" class="editable-field form-control">
                        </div>
                        <div class="info-card">
                            <div class="info-label">Phone</div>
                            <input type="text" name="phone" id="modalPhone" class="editable-field form-control">
                        </div>
                        <div class="info-card">
                            <div class="info-label">Status</div>
                            <select name="status" id="modalStatus" class="editable-field form-control">
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="banned">Banned</option>
                            </select>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Registration Date</div>
                            <div class="info-value" id="modalCreatedAt">-</div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Last Login</div>
                            <div class="info-value" id="modalLastLogin">-</div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Referral Code</div>
                            <div class="info-value" id="modalReferralCode">-</div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Referred By Admin</div>
                            <div class="info-value" id="modalReferredBy">-</div>
                        </div>
                    </div>
                    
                    <div class="form-actions" style="margin-top: 1.5rem; text-align: right;">
                        <button type="button" class="btn btn-outline" onclick="cancelEdit('basicInfoForm')">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Financial Information Tab -->
            <div id="financialInfo" class="tab-content">
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">Current Balance</div>
                        <div class="info-value" id="modalBalance">₹0.00</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Total Deposits</div>
                        <div class="info-value" id="modalTotalDeposits">₹0.00</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Total Withdrawals</div>
                        <div class="info-value" id="modalTotalWithdrawals">₹0.00</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Total Bets</div>
                        <div class="info-value" id="modalTotalBets">0</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Total Winnings</div>
                        <div class="info-value" id="modalTotalWinnings">₹0.00</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Net Profit/Loss</div>
                        <div class="info-value" id="modalNetProfitLoss">₹0.00</div>
                    </div>
                </div>

                <!-- Quick Balance Adjustment -->
                <div class="quick-adjustment" style="margin-top: 2rem; padding: 1.5rem; background: rgba(255,255,255,0.05); border-radius: 8px;">
                    <h4 style="margin-bottom: 1rem;">Quick Balance Adjustment</h4>
                    <form id="quickAdjustForm" class="adjust-form">
                        <input type="hidden" name="user_id" id="quickAdjustUserId">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; align-items: end;">
                            <div>
                                <label class="form-label">Action</label>
                                <select name="adjustment_type" class="form-control" required>
                                    <option value="add">Add Balance</option>
                                    <option value="subtract">Subtract Balance</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Amount (₹)</label>
                                <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                            </div>
                            <div>
                                <label class="form-label">Reason</label>
                                <input type="text" name="reason" class="form-control" placeholder="Adjustment reason" required>
                            </div>
                        </div>
                        <div style="margin-top: 1rem; text-align: right;">
                            <button type="submit" class="btn btn-primary">Apply Adjustment</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Activity Information Tab -->
            <div id="activityInfo" class="tab-content">
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">Total Games Played</div>
                        <div class="info-value" id="modalTotalGames">0</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Winning Rate</div>
                        <div class="info-value" id="modalWinningRate">0%</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Favorite Game</div>
                        <div class="info-value" id="modalFavoriteGame">-</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Bet Date</div>
                        <div class="info-value" id="modalLastBetDate">-</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Active Sessions</div>
                        <div class="info-value" id="modalActiveSessions">0</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Account Age</div>
                        <div class="info-value" id="modalAccountAge">-</div>
                    </div>
                </div>

                <!-- Recent Activity Chart Placeholder -->
                <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(255,255,255,0.05); border-radius: 8px;">
                    <h4 style="margin-bottom: 1rem;">Recent Activity</h4>
                    <div id="activityChart" style="height: 200px; display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                        <i class="fas fa-chart-line" style="font-size: 3rem;"></i>
                        <span style="margin-left: 1rem;">Activity chart will be displayed here</span>
                    </div>
                </div>
            </div>

            <!-- Betting History Tab -->
            <div id="bettingHistory" class="tab-content">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Game</th>
                                <th>Type</th>
                                <th>Numbers</th>
                                <th>Amount</th>
                                <th>Potential Win</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="bettingHistoryBody">
                            <tr>
                                <td colspan="7" class="loading-content">
                                    <i class="fas fa-spinner fa-spin"></i> Loading betting history...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="bettingPagination">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>

            <!-- Transactions Tab -->
            <div id="transactions" class="tab-content">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Balance Before</th>
                                <th>Balance After</th>
                                <th>Description</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsBody">
                            <tr>
                                <td colspan="7" class="loading-content">
                                    <i class="fas fa-spinner fa-spin"></i> Loading transactions...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="transactionsPagination">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Adjust Balance Modal -->
<div class="modal" id="adjustBalanceModal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-coins"></i> Adjust User Balance</h3>
            <button class="close-modal" onclick="closeModal('adjustBalanceModal')">&times;</button>
        </div>
        <form id="adjustBalanceForm" method="POST">
            <input type="hidden" name="user_id" id="adjust_user_id">
            <input type="hidden" name="adjust_balance" value="1">
            
            <div class="form-group">
                <label class="form-label">User</label>
                <input type="text" class="form-control" id="adjust_user_name" readonly>
            </div>
            
            <div class="form-group">
                <label class="form-label">Current Balance</label>
                <input type="text" class="form-control" id="current_balance" readonly>
            </div>
            
            <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="adjustment_type" class="form-control" required>
                        <option value="add" style='background-color: var(--dark);'>Add Balance</option>
                        <option value="subtract" style='background-color: var(--dark);'>Subtract Balance</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount (₹)</label>
                    <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Reason</label>
                <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for balance adjustment" required></textarea>
            </div>
            
            <div class="modal-actions" style="margin-top: 1.5rem; text-align: right;">
                <button type="button" class="btn btn-outline" onclick="closeModal('adjustBalanceModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Adjust Balance</button>
            </div>
        </form>
    </div>
</div>