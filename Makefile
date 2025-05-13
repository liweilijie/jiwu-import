# Makefile

SERVER = 192.168.1.253
SERVER_DIR = /home/bst/jiwu-import
SSH_PORT = 22

upload:
	@echo "Deploying to server $(SERVER) at $(SERVER_DIR)..."
	@rsync -av -e "ssh -p $(SSH_PORT)" --exclude-from='exclude.conf' . bst@$(SERVER):$(SERVER_DIR)