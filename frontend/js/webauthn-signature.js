/**
 * WebAuthn 簽名組件
 * 可在多個頁面重複使用的 WebAuthn 簽名功能
 */

class WebAuthnSignature {
    constructor(options = {}) {
        this.userId = options.userId || 0;
        this.documentId = options.documentId || null;
        this.documentType = options.documentType || 'general';
        this.onSuccess = options.onSuccess || null;
        this.onError = options.onError || null;
        this.containerId = options.containerId || 'webauthnSignatureContainer';
        this.showCanvasOption = options.showCanvasOption !== false; // 預設顯示 Canvas 選項
        
        this.authResult = null;
        this.init();
    }
    
    init() {
        this.checkSupport();
    }
    
    checkSupport() {
        if (!window.PublicKeyCredential) {
            console.warn('WebAuthn 不支援，將使用傳統簽名方式');
            return false;
        }
        return true;
    }
    
    /**
     * 開始 WebAuthn 認證
     */
    async authenticate() {
        if (!this.checkSupport()) {
            if (this.onError) {
                this.onError('您的瀏覽器不支援 WebAuthn');
            }
            return false;
        }
        
        try {
            // 1. 獲取認證選項
            const startResponse = await fetch('webauthn_authenticate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start' })
            });
            
            const startData = await startResponse.json();
            
            if (!startData.success) {
                throw new Error(startData.message || '獲取認證選項失敗');
            }
            
            // 2. 轉換選項中的 challenge 為 ArrayBuffer
            const options = startData.options;
            options.challenge = this.base64UrlToArrayBuffer(options.challenge);
            
            // 轉換 allowCredentials 中的 id 為 ArrayBuffer
            if (options.allowCredentials && Array.isArray(options.allowCredentials)) {
                options.allowCredentials = options.allowCredentials.map(cred => ({
                    ...cred,
                    id: this.base64UrlToArrayBuffer(cred.id)
                }));
            }
            
            // 3. 調用 WebAuthn API
            const credential = await navigator.credentials.get({
                publicKey: options
            });
            
            // 3. 發送認證結果到後端
            const authResponse = await fetch('webauthn_authenticate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'complete',
                    credential: {
                        id: credential.id,
                        rawId: this.arrayBufferToBase64(credential.rawId),
                        response: {
                            authenticatorData: this.arrayBufferToBase64(credential.response.authenticatorData),
                            clientDataJSON: this.arrayBufferToBase64(credential.response.clientDataJSON),
                            signature: this.arrayBufferToBase64(credential.response.signature),
                            userHandle: credential.response.userHandle ? this.arrayBufferToBase64(credential.response.userHandle) : null
                        },
                        type: credential.type
                    }
                })
            });
            
            const authData = await authResponse.json();
            
            if (!authData.success) {
                throw new Error(authData.message || '認證失敗');
            }
            
            // 4. 儲存認證結果
            this.authResult = authData;
            
            // 5. 提交簽名
            return await this.submitSignature();
            
        } catch (error) {
            console.error('WebAuthn 認證錯誤:', error);
            if (this.onError) {
                this.onError(error.message || '認證失敗');
            }
            return false;
        }
    }
    
    /**
     * 提交簽名到後端
     */
    async submitSignature() {
        if (!this.authResult) {
            if (this.onError) {
                this.onError('請先完成生物驗證');
            }
            return false;
        }
        
        try {
            const response = await fetch('save_signature.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    webauthn_auth: this.authResult,
                    user_id: this.userId,
                    document_id: this.documentId,
                    document_type: this.documentType,
                    timestamp: new Date().toISOString(),
                    authentication_method: 'webauthn'
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                if (this.onSuccess) {
                    this.onSuccess(data);
                }
                return true;
            } else {
                throw new Error(data.message || '儲存失敗');
            }
        } catch (error) {
            console.error('儲存簽名錯誤:', error);
            if (this.onError) {
                this.onError(error.message || '儲存失敗');
            }
            return false;
        }
    }
    
    /**
     * 註冊新設備
     */
    async registerDevice() {
        if (!this.checkSupport()) {
            if (this.onError) {
                this.onError('您的瀏覽器不支援 WebAuthn');
            }
            return false;
        }
        
        try {
            // 1. 獲取註冊選項
            const startResponse = await fetch('webauthn_register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start' })
            });
            
            const startData = await startResponse.json();
            
            if (!startData.success) {
                throw new Error(startData.message || '獲取註冊選項失敗');
            }
            
            // 2. 轉換選項中的 challenge 和 user.id 為 ArrayBuffer
            const options = startData.options;
            options.challenge = this.base64UrlToArrayBuffer(options.challenge);
            options.user.id = this.base64UrlToArrayBuffer(options.user.id);
            
            // 3. 調用 WebAuthn API
            const credential = await navigator.credentials.create({
                publicKey: options
            });
            
            // 3. 發送註冊結果到後端
            const registerResponse = await fetch('webauthn_register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'complete',
                    credential: {
                        id: credential.id,
                        rawId: this.arrayBufferToBase64(credential.rawId),
                        response: {
                            attestationObject: this.arrayBufferToBase64(credential.response.attestationObject),
                            clientDataJSON: this.arrayBufferToBase64(credential.response.clientDataJSON)
                        },
                        type: credential.type
                    }
                })
            });
            
            const registerData = await registerResponse.json();
            
            if (!registerData.success) {
                throw new Error(registerData.message || '註冊失敗');
            }
            
            if (this.onSuccess) {
                this.onSuccess({
                    type: 'register',
                    data: registerData,
                    emailVerificationRequired: !!registerData.email_verification_required
                });
            }
            return true;
            
        } catch (error) {
            console.error('WebAuthn 註冊錯誤:', error);
            if (this.onError) {
                this.onError(error.message || '註冊失敗');
            }
            return false;
        }
    }
    
    /**
     * ArrayBuffer 轉 Base64
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }
    
    /**
     * Base64 URL 解碼並轉換為 ArrayBuffer
     */
    base64UrlToArrayBuffer(base64url) {
        // 將 base64url 轉換為標準 base64
        let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        // 補齊 padding
        while (base64.length % 4) {
            base64 += '=';
        }
        // 解碼為二進制字串
        const binaryString = atob(base64);
        // 轉換為 ArrayBuffer
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }
}

// 導出供其他腳本使用
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WebAuthnSignature;
}

